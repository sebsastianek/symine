# Dumps behaviour of the reference Redmine (statuses allowed, visibility, rules, permissions) as JSON
require 'json'
out = {statuses: {}, visible: {}, required: {}, readonly: {}, editable: {}, assignable: {}, allowed: {}}
users = %w(admin jsmith dlopper rhill anna).map { |l| User.find_by_login(l) } + [User.anonymous]
Issue.all.each do |i|
  users.each do |u|
    key = "#{i.subject}|#{u.login.presence || 'anonymous'}"
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
Project.all.each do |p|
  users.each do |u|
    out[:allowed]["#{p.identifier}|#{u.login.presence || 'anonymous'}"] = perms.select { |perm| u.allowed_to?(perm.to_sym, p) }
  end
end
users.each { |u| out[:allowed]["*global*|#{u.login.presence || 'anonymous'}"] = perms.select { |perm| u.allowed_to_globally?(perm.to_sym) } }
puts JSON.generate(out)
