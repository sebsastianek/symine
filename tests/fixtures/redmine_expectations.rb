# Dumps behaviour of the reference Redmine as JSON (the oracle of tests/Integration/RedmineParityTest.php and
# tests/Parity/*). Run it on a pristine copy of the seeded database, so that data added to the shared reference
# instance by other tests does not leak into the oracle:
#
#   docker cp tests/fixtures/redmine-seeded.db symine-redmine-ref:/tmp/oracle.db
#   docker cp tests/fixtures/redmine_expectations.rb symine-redmine-ref:/tmp/
#   docker exec -e RAILS_ENV=production -e DATABASE_URL=sqlite3:/tmp/oracle.db symine-redmine-ref \
#     bin/rails runner /tmp/redmine_expectations.rb > tests/fixtures/redmine-expectations.json
require 'json'
out = {statuses: {}, visible: {}, required: {}, readonly: {}, editable: {}, assignable: {}, allowed: {}}
logins = %w(admin jsmith dlopper rhill anna)
users = logins.map { |l| User.find_by_login(l) } + [User.anonymous]
name_of = ->(u) { u.login.presence || 'anonymous' }
Issue.order(:id).each do |i|
  users.each do |u|
    key = "#{i.subject}|#{name_of[u]}"
    User.current = u
    out[:visible][key] = i.visible?(u)
    out[:statuses][key] = i.new_statuses_allowed_to(u).map(&:name)
    out[:required][key] = i.required_attribute_names(u)
    out[:readonly][key] = i.read_only_attribute_names(u)
    out[:editable][key] = [i.attributes_editable?(u), i.notes_addable?(u), i.deletable?(u)]
  end
  out[:assignable][i.subject] = i.assignable_users.map { |p| p.name }
end
perms = %w(view_issues add_issues edit_issues delete_issues log_time view_wiki_pages manage_members view_news add_messages view_private_notes)
Project.order(:id).each do |p|
  users.each do |u|
    out[:allowed]["#{p.identifier}|#{name_of[u]}"] = perms.select { |perm| u.allowed_to?(perm.to_sym, p) }
  end
end
users.each { |u| out[:allowed]["*global*|#{name_of[u]}"] = perms.select { |perm| u.allowed_to_globally?(perm.to_sym) } }

# --- visible projects (Project.visible, Project.allowed_to) -------------------------------------------------
out[:visible_projects] = {}
out[:projects_allowed_to] = {}
users.each do |u|
  User.current = u
  out[:visible_projects][name_of[u]] = Project.visible(u).order(:id).pluck(:id)
  %w(view_issues view_time_entries view_wiki_pages log_time add_issues view_news).each do |perm|
    out[:projects_allowed_to]["#{perm}|#{name_of[u]}"] = Project.allowed_to(u, perm.to_sym).order(:id).pluck(:id)
  end
end

# --- activity (Redmine::Activity::Fetcher), events as sorted "event_type#id" --------------------------------
out[:activity] = {}
[nil, Project.find_by_identifier('ecommerce'), Project.find_by_identifier('internal')].each do |project|
  users.each do |u|
    User.current = u
    [:all, :default].each do |scope|
      f = Redmine::Activity::Fetcher.new(u, project: project, with_subprojects: true)
      f.scope = scope
      out[:activity]["#{project ? project.identifier : '*'}|#{scope}|#{name_of[u]}"] = f.events(nil, nil).map { |e| "#{e.event_type}##{e.id}" }.sort
    end
  end
end

# --- search (Redmine::Search::Fetcher), results as "type#id" in rank order ---------------------------------
out[:search] = {}
questions = ['wishlist', 'cart', 'crash', 'safari', 'release', 'hello', 'started working', 'specs', 'checkout', 'resetting password', 'great', 'reply', 'version', 'first post', '"wrong with"', 'acme']
users.each do |u|
  User.current = u
  questions.each do |q|
    [[{}, 'all'], [{all_words: false}, 'any'], [{titles_only: true}, 'titles']].each do |options, label|
      f = Redmine::Search::Fetcher.new(q, u, Redmine::Search.available_search_types, nil, options.dup)
      out[:search]["#{q}|#{label}|#{name_of[u]}"] = f.result_ids.map { |scope, id| "#{scope}##{id}" }
    end
  end
end

# --- notified users / watchers (Issue#notified_users, #notified_watchers) for several preference sets ------
out[:notified] = {}
scenarios = {
  'default' => -> {},
  'all' => -> { User.where(type: 'User').update_all(mail_notification: 'all') },
  'mixed' => lambda {
    User.find_by_login('admin').update_column(:mail_notification, 'none')
    User.find_by_login('jsmith').update_column(:mail_notification, 'selected')
    Member.where(user_id: User.find_by_login('jsmith').id, project_id: Project.find_by_identifier('ecommerce').id).update_all(mail_notification: true)
    User.find_by_login('dlopper').update_column(:mail_notification, 'only_my_events')
    User.find_by_login('rhill').update_column(:mail_notification, 'only_owner')
    User.find_by_login('anna').update_column(:mail_notification, 'all')
  },
}
scenarios.each do |label, setup|
  ActiveRecord::Base.transaction do
    setup.call
    Issue.order(:id).each do |i|
      i = Issue.find(i.id)
      out[:notified]["#{i.id}|#{label}|users"] = i.notified_users.map { |u| u.login }.sort
      out[:notified]["#{i.id}|#{label}|watchers"] = i.notified_watchers.map { |u| u.login }.sort
    end
    raise ActiveRecord::Rollback
  end
end

# --- time entries (TimeEntry.visible) ------------------------------------------------------------------------
out[:time_entries] = {}
{'default' => -> {}, 'own' => -> { Role.where(builtin: [1, 2]).or(Role.where(name: 'Reporter')).update_all(time_entries_visibility: 'own') }}.each do |label, setup|
  ActiveRecord::Base.transaction do
    setup.call
    users.each do |u|
      u = u.anonymous? ? User.anonymous : User.find(u.id)
      User.current = u
      out[:time_entries]["#{label}|#{name_of[u]}"] = TimeEntry.visible(u).order(:id).pluck(:id)
      q = TimeEntryQuery.new(name: '_')
      q.add_filter('spent_on', '*', [''])
      q.totalable_names = [:hours]
      out[:time_entries]["#{label}|#{name_of[u]}|total"] = [q.results_scope.count, q.total_for(:hours).to_f.round(2)]
    end
    raise ActiveRecord::Rollback
  end
end

# --- issue query totals ----------------------------------------------------------------------------------------
out[:totals] = {}
[nil, Project.find_by_identifier('ecommerce')].each do |project|
  users.each do |u|
    User.current = u
    q = IssueQuery.new(name: '_', project: project)
    q.add_filter('status_id', '*', [''])
    q.totalable_names = [:estimated_hours, :spent_hours]
    key = "#{project ? project.identifier : '*'}|#{name_of[u]}"
    out[:totals][key] = {
      'count' => q.issue_count,
      'estimated_hours' => q.total_for(:estimated_hours).to_f.round(2),
      'spent_hours' => q.total_for(:spent_hours).to_f.round(2),
    }
    q.group_by = 'tracker'
    out[:totals][key]['by_tracker'] = q.result_count_by_group.transform_keys { |t| t ? t.name : '' }.sort.to_h
    out[:totals][key]['estimated_by_tracker'] = q.total_by_group_for(:estimated_hours).transform_keys { |t| t ? t.name : '' }.transform_values { |v| v.to_f.round(2) }.sort.to_h
  end
end
User.current = nil
puts JSON.generate(out)
