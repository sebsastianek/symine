# Seeds a reference Redmine instance with representative data (run with: bin/rails runner seed_redmine.rb)
User.current = User.find(1)
admin = User.find(1)
admin.update_columns(must_change_passwd: false)
Setting.rest_api_enabled = '1'
Setting.text_formatting = 'common_mark'
Setting.app_title = 'Redmine Reference'
Setting.welcome_text = "Welcome to the **reference** instance."
Setting.issue_group_assignment = '1'
Setting.cross_project_issue_relations = '1'
Setting.parent_issue_dates = 'derived'
Setting.parent_issue_done_ratio = 'derived'

manager, developer, reporter = Role.find_by(name: 'Manager'), Role.find_by(name: 'Developer'), Role.find_by(name: 'Reporter')
bug, feature, support = Tracker.find_by(name: 'Bug'), Tracker.find_by(name: 'Feature'), Tracker.find_by(name: 'Support')
st = IssueStatus.all.index_by(&:name)
prio = IssuePriority.all.index_by(&:name)

def mkuser(login, first, last, extra = {})
  u = User.find_by(login: login) || User.new(login: login, firstname: first, lastname: last, mail: "#{login}@example.net", language: 'en')
  u.password = u.password_confirmation = 'password123'
  u.assign_attributes(extra)
  u.save!
  u
end
jsmith = mkuser('jsmith', 'John', 'Smith')
dlopper = mkuser('dlopper', 'Dave', 'Lopper')
rhill = mkuser('rhill', 'Robert', 'Hill')
locked = mkuser('locked', 'Lock', 'Ed')
locked.lock!
anna = mkuser('anna', 'Anna', 'Müller', language: 'de')
anna.pref.update!(time_zone: 'Berlin', comments_sorting: 'desc')
key = jsmith.api_key

group = Group.find_by(lastname: 'Developers') || Group.create!(lastname: 'Developers')
group.users << dlopper unless group.users.include?(dlopper)

cf_text = IssueCustomField.find_by(name: 'Database') || IssueCustomField.create!(name: 'Database', field_format: 'list', possible_values: ['MySQL', 'PostgreSQL', 'Oracle'], is_for_all: true, is_filter: true, trackers: [bug, feature])
cf_multi = IssueCustomField.find_by(name: 'Platforms') || IssueCustomField.create!(name: 'Platforms', field_format: 'list', possible_values: ['Linux', 'macOS', 'Windows'], multiple: true, is_for_all: true, trackers: [bug])
cf_float = IssueCustomField.find_by(name: 'Budget') || IssueCustomField.create!(name: 'Budget', field_format: 'float', is_for_all: true, trackers: [feature], visible: false, role_ids: [manager.id])
cf_bool = IssueCustomField.find_by(name: 'Searchable') || IssueCustomField.create!(name: 'Searchable', field_format: 'bool', is_for_all: true, trackers: [bug, feature, support])
pcf = ProjectCustomField.find_by(name: 'Customer') || ProjectCustomField.create!(name: 'Customer', field_format: 'string')
ucf = UserCustomField.find_by(name: 'Phone') || UserCustomField.create!(name: 'Phone', field_format: 'string', visible: true)
ecf = IssueCustomField.find_by(name: 'Severity') || IssueCustomField.create!(name: 'Severity', field_format: 'enumeration', is_for_all: true, trackers: [bug])
if ecf.enumerations.empty?
  %w(Minor Major Critical).each_with_index { |n, i| ecf.enumerations.create!(name: n, position: i + 1) }
end
jsmith.custom_field_values = {ucf.id => '+1 555 0100'}; jsmith.save!

# workflow field permission: Developer must fill due date when resolving bugs
WorkflowPermission.where(tracker_id: bug.id, old_status_id: st['In Progress'].id, role_id: developer.id, field_name: 'due_date').first_or_create!(rule: 'required')
WorkflowPermission.where(tracker_id: bug.id, old_status_id: st['Resolved'].id, role_id: reporter.id, field_name: 'subject').first_or_create!(rule: 'readonly')

def mkproject(attrs)
  Project.find_by(identifier: attrs[:identifier]) || Project.create!(attrs)
end
ecommerce = mkproject(name: 'eCommerce', identifier: 'ecommerce', description: 'Online **shop** project', is_public: true, enabled_module_names: Redmine::AccessControl.available_project_modules.map(&:to_s), tracker_ids: [bug.id, feature.id, support.id], issue_custom_field_ids: [cf_text.id])
ecommerce.custom_field_values = {pcf.id => 'ACME Corp'}; ecommerce.save!
private_p = mkproject(name: 'Internal Tools', identifier: 'internal', is_public: false, enabled_module_names: %w(issue_tracking time_tracking wiki), tracker_ids: [bug.id, feature.id])
sub = mkproject(name: 'Checkout', identifier: 'checkout', parent_id: ecommerce.id, is_public: true, enabled_module_names: %w(issue_tracking time_tracking news), tracker_ids: [bug.id, feature.id])
archived = mkproject(name: 'Legacy', identifier: 'legacy', is_public: true, enabled_module_names: %w(issue_tracking), tracker_ids: [bug.id])

[[ecommerce, jsmith, [manager]], [ecommerce, dlopper, [developer]], [ecommerce, rhill, [reporter]], [private_p, jsmith, [developer]], [sub, jsmith, [manager]]].each do |p, u, roles|
  Member.create!(project: p, principal: u, roles: roles) unless Member.exists?(project: p, user_id: u.id)
end
Member.create!(project: ecommerce, principal: group, roles: [developer]) unless Member.exists?(project: ecommerce, user_id: group.id)

v1 = ecommerce.versions.find_by(name: '1.0') || ecommerce.versions.create!(name: '1.0', effective_date: Date.today + 30, sharing: 'descendants', description: 'First release')
v0 = ecommerce.versions.find_by(name: '0.9') || ecommerce.versions.create!(name: '0.9', effective_date: Date.today - 30, status: 'closed')
cat = ecommerce.issue_categories.find_by(name: 'UI') || ecommerce.issue_categories.create!(name: 'UI', assigned_to: dlopper)
ecommerce.issue_categories.find_by(name: 'Backend') || ecommerce.issue_categories.create!(name: 'Backend')

if Issue.count == 0
  User.current = jsmith
  i1 = Issue.create!(project: ecommerce, tracker: bug, subject: 'Cart total is wrong with discounts', assigned_to: group, description: "Steps:\n\n1. Add item\n2. Apply coupon\n\nSee #2 and [[Checkout flow]]", author: jsmith, priority: prio['High'], fixed_version: v1, category: cat, start_date: Date.today - 5, due_date: Date.today + 5, estimated_hours: 8, custom_field_values: {cf_text.id => 'PostgreSQL', cf_multi.id => ['Linux', 'macOS'], cf_bool.id => '1'})
  i2 = Issue.create!(project: ecommerce, tracker: feature, subject: 'Wishlist', author: rhill, priority: prio['Normal'], custom_field_values: {cf_float.id => '1500.5'})
  i3 = Issue.create!(project: ecommerce, tracker: feature, subject: 'Wishlist sharing', author: jsmith, priority: prio['Low'], parent_issue_id: i2.id, start_date: Date.today, due_date: Date.today + 10, estimated_hours: 4, done_ratio: 50)
  i4 = Issue.create!(project: ecommerce, tracker: feature, subject: 'Wishlist emails', author: jsmith, priority: prio['Urgent'], parent_issue_id: i2.id, start_date: Date.today + 2, due_date: Date.today + 12, estimated_hours: 2)
  i5 = Issue.create!(project: sub, tracker: bug, subject: 'Payment fails on Safari', author: rhill, priority: prio['Immediate'], assigned_to: jsmith, is_private: true)
  i6 = Issue.create!(project: private_p, tracker: bug, subject: 'Internal tool crash', author: jsmith, priority: prio['Normal'])
  i7 = Issue.create!(project: ecommerce, tracker: support, subject: 'Customer cannot login', author: jsmith, priority: prio['Normal'], status: st['New'])
  IssueRelation.create!(issue_from: i1, issue_to: i7, relation_type: 'blocks')
  IssueRelation.create!(issue_from: i3, issue_to: i4, relation_type: 'precedes', delay: 1)
  IssueRelation.create!(issue_from: i5, issue_to: i1, relation_type: 'relates')
  i1.reload
  i1.init_journal(dlopper, 'Started working on it')
  i1.status = st['In Progress']; i1.done_ratio = 30
  i1.save!
  i1.reload
  i1.init_journal(jsmith, "Private remark for devs")
  i1.private_notes = true
  i1.save!
  i7.reload
  i7.init_journal(jsmith, 'Resolved by resetting password')
  i7.status = st['Closed']
  i7.save!
  Watcher.create!(watchable: i1, user: rhill)
  Watcher.create!(watchable: i1, user: jsmith) rescue nil
  att = Attachment.new(file: StringIO.new("hello world\n"), author: jsmith, filename: 'notes.txt', content_type: 'text/plain')
  att.container = i1; att.save!
  act = TimeEntryActivity.first
  TimeEntry.create!(project: ecommerce, issue: i1, user: dlopper, author: dlopper, hours: 2.5, activity: act, spent_on: Date.today - 1, comments: 'Investigation')
  TimeEntry.create!(project: ecommerce, user: jsmith, author: jsmith, hours: 1, activity: act, spent_on: Date.today, comments: 'Meeting')
  # wiki
  wiki = ecommerce.wiki || Wiki.create!(project: ecommerce, start_page: 'Wiki')
  page = WikiPage.new(wiki: wiki, title: 'Wiki'); page.content = WikiContent.new(text: "# Home\n\nSee [[Checkout flow]]", author: jsmith); page.save!
  p2 = WikiPage.new(wiki: wiki, title: 'Checkout_flow', parent: page); p2.content = WikiContent.new(text: "Version 1", author: jsmith); p2.save!
  p2.content.text = "Version 2 with more text"; p2.content.author = dlopper; p2.content.comments = 'update'; p2.content.save!
  # news, comments
  n = News.create!(project: ecommerce, title: 'Release 0.9', summary: 'We shipped', description: 'Details...', author: jsmith)
  Comment.create!(commented: n, author: rhill, content: 'Great!')
  # documents
  Document.create!(project: ecommerce, title: 'Specs', description: 'Specification', category: DocumentCategory.first)
  # forums
  b = Board.create!(project: ecommerce, name: 'General', description: 'General discussion')
  m = Message.create!(board: b, subject: 'Hello', content: 'First post', author: jsmith)
  Message.create!(board: b, parent: m, subject: 'RE: Hello', content: 'Reply', author: rhill)
  # saved query
  q = IssueQuery.new(name: 'Open bugs', project: ecommerce, user: jsmith, visibility: IssueQuery::VISIBILITY_PUBLIC)
  q.add_filter('tracker_id', '=', [bug.id.to_s]); q.add_filter('status_id', 'o', [''])
  q.column_names = [:tracker, :status, :subject, :assigned_to, :updated_on]; q.sort_criteria = [['priority', 'desc'], ['id', 'desc']]
  q.save!
  # archive
  archived.archive
end
puts "Seeded. jsmith API key: #{key}"
puts "Counts: users=#{User.count} projects=#{Project.count} issues=#{Issue.count} journals=#{Journal.count} details=#{JournalDetail.count}"
