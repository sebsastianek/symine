# Issue CRUD Operations - Test Plan

**Project:** Symfony Redmine Clone
**Feature:** Issue Management (CRUD + Comments + Workflows)
**Date:** November 5, 2025
**Status:** Phase 1 Complete

---

## Test Environment Setup

### Prerequisites
1. Database with schema migrated (run `doctrine:schema:update --force`)
2. Test data loaded with fixtures
3. At least 3 test users with different roles:
   - Admin user
   - Manager (with issue edit permissions)
   - Developer (with limited permissions)
4. At least 2 test projects
5. Issue statuses configured
6. Trackers configured (Bug, Feature, Task)
7. Priorities configured (Low, Normal, High)

### Setup Commands
```bash
# Create database
php bin/console doctrine:database:create

# Generate schema
php bin/console doctrine:schema:update --force

# Load fixtures (if available)
php bin/console doctrine:fixtures:load --no-interaction

# Start server
php bin/console server:start
# OR
symfony server:start
```

---

## Test Cases

### TC-001: View Issue List
**Objective:** Verify users can view list of issues in a project

**Steps:**
1. Login as any user
2. Navigate to Projects page
3. Click on a project
4. Click on "Issues" tab or navigate to `/projects/{id}/issues`

**Expected Results:**
- ✅ Issue list displays in table format
- ✅ Columns shown: #, Tracker, Subject, Status, Assigned, Updated
- ✅ Issues are sorted by ID descending (newest first)
- ✅ Status badges display with correct colors
- ✅ Private issues show lock icon 🔒
- ✅ Clickable issue numbers and subjects
- ✅ "New Issue" button visible if user has create permission
- ✅ Empty state shown if no issues exist

**Test Data:**
- Project with 5-10 existing issues
- Mix of open and closed issues
- At least one private issue

---

### TC-002: Create New Issue
**Objective:** Verify users can create new issues

**Steps:**
1. Login as user with "add_issues" permission
2. Navigate to project issues list
3. Click "New Issue" button
4. Fill in required fields:
   - Subject: "Test Issue #1"
   - Tracker: Bug
   - Status: New
   - Priority: Normal
5. Fill in optional fields:
   - Description: "This is a test issue"
   - Assigned To: Select a user
   - Start Date: Today
   - Due Date: +7 days
   - Estimated Hours: 5
   - % Done: 0
6. Click "Create Issue"

**Expected Results:**
- ✅ Form displays all fields
- ✅ Required fields marked with asterisk (*)
- ✅ Dropdowns populate with correct data
- ✅ Dropdowns filtered by project (assignees, categories, versions)
- ✅ Form validation works (try submitting empty subject)
- ✅ Issue created successfully
- ✅ Redirected to issue show page
- ✅ Flash message: "Issue #{id} created successfully."
- ✅ All entered data displays correctly
- ✅ Author set to current user
- ✅ Created/Updated timestamps set

**Test Data:**
- Project with active members
- Multiple trackers available
- Multiple statuses available

---

### TC-003: View Issue Details
**Objective:** Verify issue details display correctly

**Steps:**
1. Login as any user
2. Navigate to an existing issue
3. Review all displayed information

**Expected Results:**
- ✅ Breadcrumb navigation works
- ✅ Issue header shows: Tracker badge, Status badge, Subject
- ✅ Private badge shown if issue is private
- ✅ Author and creation date displayed
- ✅ Edit button visible if user has permission
- ✅ Delete button visible if user has permission
- ✅ Description section shows formatted text
- ✅ Empty state for no description
- ✅ Details sidebar shows all fields:
  - Status, Priority, Assigned to
  - Category, Target version
  - Start/Due dates
  - Estimated hours
  - % Done with progress bar
  - Created/Updated timestamps
- ✅ Activity section displays (even if empty)
- ✅ Comment form visible if user can comment
- ✅ Subtasks section if issue has children

**Test Data:**
- Issue with all fields populated
- Issue with minimal data (required only)
- Issue with subtasks

---

### TC-004: Edit Existing Issue
**Objective:** Verify users can edit issues and changes are tracked

**Steps:**
1. Login as user with "edit_issues" permission
2. Navigate to an existing issue
3. Click "Edit" button
4. Modify multiple fields:
   - Subject: Add " - Modified"
   - Status: Change to "In Progress"
   - Priority: Change to "High"
   - % Done: Change to 25
5. Click "Update Issue"

**Expected Results:**
- ✅ Edit form pre-populated with current values
- ✅ All fields editable
- ✅ Parent issue dropdown available
- ✅ Issue updated successfully
- ✅ Redirected to issue show page
- ✅ Flash message: "Issue #{id} updated successfully."
- ✅ Changed values display correctly
- ✅ Updated timestamp changed
- ✅ Journal entry created showing changes:
  - "Status: changed from New to In Progress"
  - "Priority: changed from Normal to High"
  - "% Done: changed from 0 to 25"
- ✅ Activity timeline shows the update

**Test Data:**
- Issue with existing data
- User with appropriate permissions

---

### TC-005: Add Comment to Issue
**Objective:** Verify users can add comments

**Steps:**
1. Login as user with "add_issue_notes" permission
2. Navigate to an existing issue
3. Scroll to "Add a Comment" section
4. Enter comment text: "This is a test comment"
5. Optionally check "Private notes"
6. Click "Add Comment"

**Expected Results:**
- ✅ Comment form visible to authorized users
- ✅ Comment text area accepts input
- ✅ Private notes checkbox available
- ✅ Comment submitted successfully
- ✅ Flash message: "Comment added successfully."
- ✅ Page refreshes/redirects to issue
- ✅ New comment appears in Activity section
- ✅ Comment shows: user name, timestamp, text
- ✅ Private badge shown if private
- ✅ Comment text preserves line breaks

**Test Data:**
- Issue with no comments
- Issue with existing comments
- Multi-line comment text

---

### TC-006: Status Transition with Workflow
**Objective:** Verify workflow rules enforce allowed status transitions

**Steps:**
1. Login as Developer (non-admin)
2. Navigate to an issue with status "New"
3. Click "Edit"
4. Try to change status to "Closed" (if workflow doesn't allow this)
5. Click "Update Issue"

**Expected Results:**
- ✅ Form displays with all statuses
- ✅ User can select any status in dropdown
- ✅ If transition NOT allowed by workflow:
  - ✅ Error flash message displayed
  - ✅ Message: "Status transition from 'New' to 'Closed' is not allowed by workflow rules."
  - ✅ Form re-rendered with error
  - ✅ Status reverted to original
  - ✅ No changes saved to database
  - ✅ No journal entry created
- ✅ If transition IS allowed:
  - ✅ Status changes successfully
  - ✅ Journal entry created
  - ✅ Workflow logged

**Test Data:**
- Workflow rules configured (if available)
- Admin user (should bypass workflow)
- Non-admin user (should follow workflow)

**Alternative Test:**
Login as Admin and repeat - should succeed regardless of workflow

---

### TC-007: Delete Issue
**Objective:** Verify users can delete issues

**Steps:**
1. Login as user with "delete_issues" permission
2. Navigate to an issue (preferably a test issue)
3. Click "Delete" button
4. Confirm deletion in JavaScript alert

**Expected Results:**
- ✅ Delete button visible to authorized users
- ✅ Confirmation dialog appears
- ✅ If confirmed:
  - ✅ Issue deleted from database
  - ✅ Redirected to issue list
  - ✅ Flash message: "Issue #{id} deleted successfully."
  - ✅ Issue no longer appears in list
- ✅ If cancelled:
  - ✅ Issue not deleted
  - ✅ User remains on issue page

**Test Data:**
- Test issue that can be safely deleted
- User with delete permission

---

### TC-008: Private Issues
**Objective:** Verify privacy controls work correctly

**Steps:**
1. Login as user A
2. Create a new issue
3. Check "Private" checkbox
4. Submit issue
5. Logout and login as user B (different user, non-admin)
6. Try to view the private issue

**Expected Results:**
- ✅ Private checkbox available in form
- ✅ Private issue created
- ✅ Lock icon 🔒 shown in issue list
- ✅ Private badge shown in issue header
- ✅ Author can view own private issue
- ✅ Assigned user can view private issue
- ✅ Admin can view all private issues
- ✅ Other users CANNOT view private issue
- ✅ Private issue filtered from list for unauthorized users

**Test Data:**
- Multiple users with different permissions
- Admin user
- Author user
- Unrelated user

---

### TC-009: Form Validation
**Objective:** Verify form validation prevents invalid data

**Steps:**
1. Login and navigate to new issue form
2. Test each validation:
   - Submit with empty subject
   - Submit with subject >255 characters
   - Submit without tracker
   - Submit without status
   - Submit without priority
   - Submit with negative estimated hours
   - Submit with % done > 100
   - Submit with % done < 0

**Expected Results:**
- ✅ Subject required - error shown
- ✅ Subject max length enforced
- ✅ Tracker required - error shown
- ✅ Status required - error shown
- ✅ Priority required - error shown
- ✅ Estimated hours must be ≥ 0
- ✅ % Done must be 0-100
- ✅ Error messages clear and helpful
- ✅ Form data preserved after validation error
- ✅ No database changes on validation failure

---

### TC-010: Permission Checks
**Objective:** Verify permission system works correctly

**Test Matrix:**

| Action | Admin | Manager | Developer | Reporter | Anonymous |
|--------|-------|---------|-----------|----------|-----------|
| View issues | ✅ | ✅ | ✅ | ✅ | ❌ |
| Create issue | ✅ | ✅ | ✅ | ✅ | ❌ |
| Edit own issue | ✅ | ✅ | ✅ | ✅ | ❌ |
| Edit others' issue | ✅ | ✅ | ✅ | ❌ | ❌ |
| Delete issue | ✅ | ✅ | ❌ | ❌ | ❌ |
| Add comment | ✅ | ✅ | ✅ | ✅ | ❌ |
| View private issues | ✅ | ✅ | Own only | Own only | ❌ |
| Change status | ✅ | ✅ | Workflow | ❌ | ❌ |

**Steps:**
For each user role, test each action and verify correct behavior

**Expected Results:**
- ✅ Allowed actions succeed
- ✅ Forbidden actions return 403 or redirect
- ✅ UI elements hidden when no permission
- ✅ Error messages clear
- ✅ No security bypass possible

---

### TC-011: Activity Timeline
**Objective:** Verify activity/journal displays correctly

**Steps:**
1. Create a new issue
2. Edit the issue multiple times, changing different fields
3. Add several comments
4. View the issue's activity timeline

**Expected Results:**
- ✅ All journal entries displayed chronologically
- ✅ Each entry shows:
  - User who made change
  - Timestamp
  - Fields changed (old → new values)
  - Comments/notes
- ✅ Private notes marked with badge
- ✅ Private notes hidden from unauthorized users
- ✅ Field names formatted nicely (status_id → "Status")
- ✅ Values formatted appropriately (IDs shown as names)
- ✅ Timeline easy to read and understand
- ✅ Empty state when no activity

**Test Data:**
- Issue with multiple edits
- Mix of field changes and comments
- Private and public comments

---

### TC-012: Cross-Project Security
**Objective:** Verify users can't access issues from wrong project

**Steps:**
1. Note an issue ID from Project A
2. Try to access it via Project B URL: `/projects/{B}/issues/{issue_from_A}`

**Expected Results:**
- ✅ 404 error returned
- ✅ Error message: "Issue not found in this project"
- ✅ No information leaked
- ✅ User cannot bypass security

---

### TC-013: Issue Relationships (Parent/Subtasks)
**Objective:** Verify parent-child issue relationships

**Steps:**
1. Create a parent issue
2. Edit another issue and set parent to the first issue
3. View parent issue

**Expected Results:**
- ✅ Parent dropdown available in edit form
- ✅ Parent can be set
- ✅ Subtask saved with parent relationship
- ✅ Parent issue shows "Subtasks" section
- ✅ Subtasks listed with links
- ✅ Subtask shows link to parent (if implemented)

---

### TC-014: UI/UX Testing
**Objective:** Verify user interface quality

**Tests:**
- ✅ Responsive design works on mobile (320px, 768px, 1024px)
- ✅ All buttons have hover states
- ✅ Forms have proper spacing
- ✅ Flash messages display correctly
- ✅ Error messages are clear
- ✅ Loading states (if any)
- ✅ Breadcrumb navigation works
- ✅ Back buttons work correctly
- ✅ Tailwind CSS classes applied correctly
- ✅ No console errors in browser
- ✅ No broken images/icons
- ✅ Consistent color scheme
- ✅ Accessible (keyboard navigation, screen readers)

---

## Performance Testing

### PT-001: List Performance
- Create 100+ issues in a project
- Navigate to issue list
- ✅ Page loads in < 2 seconds
- ✅ No N+1 query problems
- ✅ Pagination works (if implemented)

### PT-002: Show Page Performance
- Create issue with 50+ journal entries
- View issue page
- ✅ Page loads in < 3 seconds
- ✅ All journals displayed
- ✅ No memory issues

---

## Security Testing

### ST-001: XSS Protection
- Create issue with script tags in subject: `<script>alert('XSS')</script>`
- View issue
- ✅ Script not executed
- ✅ Text displayed safely

### ST-002: CSRF Protection
- Try to delete issue without CSRF token
- ✅ Request rejected
- ✅ Error message shown

### ST-003: SQL Injection
- Try SQL injection in search/filter fields
- ✅ No SQL errors
- ✅ Input sanitized

---

## Regression Testing

After any code changes, rerun:
- TC-002: Create New Issue
- TC-004: Edit Existing Issue
- TC-005: Add Comment
- TC-006: Workflow Validation
- TC-010: Permission Checks

---

## Automated Testing (Future)

### Unit Tests Needed:
- `JournalService::createComment()`
- `JournalService::trackChanges()`
- `JournalService::detectIssueChanges()`
- `WorkflowService::canTransitionStatus()`
- `IssueVoter::voteOnAttribute()`

### Integration Tests Needed:
- Issue CRUD operations
- Journal entry creation
- Workflow validation
- Permission checks

### Functional/E2E Tests Needed:
- Full user journey: create → edit → comment → delete
- Multi-user scenarios
- Workflow enforcement

---

## Known Issues / Limitations

1. **Workflow Rules**: Requires workflows to be configured in database
2. **Performance**: No pagination on issue list yet
3. **Attachments**: File attachments not implemented
4. **Relations**: Issue relations (blocks, duplicates) not implemented
5. **Bulk Operations**: Bulk edit/delete not implemented
6. **Export**: CSV/PDF export not implemented
7. **Watchers**: Watcher functionality not implemented

---

## Test Sign-Off

| Tester | Role | Date | Result | Notes |
|--------|------|------|--------|-------|
| | | | | |
| | | | | |

---

## Summary

**Total Test Cases:** 14 (Manual) + 3 (Performance) + 3 (Security)
**Priority:** High (Core functionality)
**Estimated Testing Time:** 3-4 hours (manual)
**Recommended Frequency:** After each release, After major changes

**Pass Criteria:**
- All TC-001 through TC-013 pass
- No critical security issues
- Performance acceptable
- No data corruption

**Next Steps:**
1. Run all test cases manually
2. Document any failures
3. Fix identified issues
4. Write automated tests for critical paths
5. Set up CI/CD pipeline with automated tests
