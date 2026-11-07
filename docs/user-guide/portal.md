# HRMS Portal user guide

Use this guide to sign in, find your account settings, and explore the portal. The guide is also available from **User guide** in the sidebar.

## Sign in

1. Open the portal home page and select **Sign in**.
2. Enter the email address and password for your staff account. Your portal administrator provisions staff accounts. If you need to set or reset your password, use **Forgot your password?** on the sign-in page.
3. Follow the email verification prompt if it appears. If two-factor authentication is enabled for your account, follow the sign-in challenge.

## Find your way around

1. **Dashboard** shows the current time in the portal's configured time zone and shortcuts to your account and this guide.
2. **Employees** lists the profiles you may view. Open a profile to see its employee number and assignment history. Admin and HR can view profiles in their company. Managers can view their current direct reports and their own profile. Other staff can view their linked profile.
   - Admin and HR can search the directory, select **New employee**, and edit a profile's name, employee number, start date and active status. Employee numbers must be unique within the company. An inactive linked employee cannot sign in or keep using an existing session.
   - Open a profile and choose **Add assignment or transfer**. Select an active department, position and location, an optional reporting manager, and the effective start date. A transfer closes the prior open assignment on the previous day and keeps both records in the history. Use **Correct assignment** on a history entry to fix an error; the portal records the previous and replacement values for audit.
   - To give an employee portal access, open an unlinked profile, enter a unique work email and choose a role under **Invite staff account**. The portal sends a password setup link; it never displays a password. The staff member must verify their email after signing in. Admins can assign any portal role; HR cannot grant the admin role. Use **Resend password setup link** if the first message fails or expires.
   - Admin and HR can open **Organization** to search, add, rename, or deactivate departments, positions and locations. Locations require an IANA time zone such as `Asia/Dhaka`. Deactivated records remain in assignment history and cannot be selected for new assignments.
3. **My Attendance** shows your current punch state and history. Select **Check in** or **Check out** when the button is available. The portal records its own current time; you cannot enter a different time through self-service. An effective location assignment is required. A check-in left open for more than 24 hours is marked as a missing check-out, without assuming an absence or scheduled shift.
4. **Team Attendance** is available to Admin and HR for their company and to managers for current direct reports. Search or choose a date range to see effective check-in/out counts, then open an employee's history. Pending corrections do not change these counts.
   - Authorized HR/admin or a current reporting manager can make a **Manual entry** for another employee with a required reason. Enter the employee location's local date and time. Entries are limited to the previous 90 days and at most five minutes ahead by default. The portal validates the assignment location time zone. If clocks repeat an hour, provide the UTC offset (for example `-04:00`) to identify the intended instant.
   - On an original record, choose **Propose correction** to supply replacement action/time and a reason. The original remains intact. Admin or HR reviewers can approve or reject a proposal with a reason; the proposer, subject employee and original recorder cannot approve it. Approval changes the effective history after sequence validation. Rejection leaves effective history unchanged.
   - The work date of an overnight check-out stays with its check-in. History displays the stored event, source, recorder, work date and decision trail. Counts use approved corrections; no scheduled attendance or absence status is inferred.
5. **Profile** lets you update your display name and email. An email change requires verification of the new address.
6. **Security** lets you change your password and set up two-factor authentication. Enter your current password when prompted.
7. **Appearance** lets you select Light, Dark, or System. On a small screen, open the sidebar using the menu button at the top.
8. **Portal home** takes you to the public landing page. Use **Log out** in the account menu to end your session.

## Explore a local demo

Use the fictional account and temporary password supplied by the demo operator. Sign out before trying a different role. The employee directory and attendance views change according to your role and reporting relationship. Leave screens will appear in a later module.

The local demo uses pre-verified addresses and simulated mail delivery. Keep the demo email address as provided to continue exploring.
