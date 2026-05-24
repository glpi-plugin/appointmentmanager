# GLPI Appointment Manager Plugin

A comprehensive GLPI plugin for scheduling, managing, and synchronizing technician appointments with external calendars (Google Calendar, Microsoft Outlook).

## Features

- **Appointment Scheduling**: Technicians propose and manage appointments with customers on tickets
- **Status Tracking**: Proposed → Confirmed → Completed/Declined/Cancelled workflow with request reschedule option
- **Mini Calendar UI**: FullCalendar 6 integration for visual slot selection in modals
- **External Calendar Sync**: Two-way synchronization with Google Calendar and Microsoft Outlook
  - Push GLPI appointments to technician/customer calendars
  - Pull external calendar events as "busy" background slots in the GLPI mini-calendar
- **Blocked Periods**: Technicians can mark unavailable time ranges (PTO, maintenance, etc.)
- **Appointment Types**: Customizable appointment categories with color coding
- **Weekly Availability**: Technicians define recurring weekly availability windows
- **Permission Control**: Fine-grained role-based access via GLPI profiles
- **Smart Notifications**: Follow-up posts with action buttons for confirming, declining, or rescheduling

## Requirements

- GLPI ≥ 11.0.0
- PHP 7.4+
- MySQL/MariaDB
- Internet access for OAuth 2.0 authorization (if using calendar sync)

## Installation

1. **Download and extract** the plugin to your GLPI `plugins/` directory:
   ```bash
   cd /path/to/glpi/plugins
   unzip appointmentmanager.zip
   ```

2. **Install via GLPI Admin UI**:
   - Go to **Setup → Plugins**
   - Find "Appointment Manager" and click **Install**
   - Click **Enable**

3. **Database schema** is created automatically on install. If tables are missing, reinstall the plugin via the admin UI.

## Configuration

### Step 1: Configure Appointment Types
- Go to **Setup → Appointment Manager → Appointment Types**
- Add types (e.g., "On-site Support", "Phone Support", "Remote Assistance")
- Assign a color to each type for calendar visualization
- Deactivate types as needed (existing appointments are not affected)

### Step 2: Set Technician Availability
- Go to **Setup → Appointment Manager → Technician Weekly Availability**
- Select a technician (if you're an admin) or configure your own availability
- Define working hours for each day of the week
- Save

### Step 3: Configure Calendar Integrations (Optional)
If you want to sync appointments with Google Calendar or Microsoft Outlook:

#### Google Calendar
1. Go to **Setup → Appointment Manager → Calendar Integrations → Google Calendar**
2. Create a project in [Google Cloud Console](https://console.cloud.google.com/)
   - Enable "Google Calendar API"
   - Create OAuth 2.0 credentials (Desktop/Web application)
   - Set redirect URI to: `https://your-glpi-domain/plugins/appointmentmanager/front/oauth_callback.php?provider=google`
3. Copy your **Client ID** and **Client Secret** into the plugin config
4. Click **Save**
5. Click **Connect** to authorize your account

#### Microsoft Outlook / Microsoft 365
1. Go to **Setup → Appointment Manager → Calendar Integrations → Microsoft**
2. Register an app in [Azure Portal](https://portal.azure.com/)
   - Go to "App registrations" → "New registration"
   - Set redirect URI to: `https://your-glpi-domain/plugins/appointmentmanager/front/oauth_callback.php?provider=microsoft`
   - Create a client secret
3. Copy your **Client ID**, **Client Secret**, and **Tenant ID** into the plugin config
4. Click **Save**
5. Click **Connect** to authorize your account

### Step 4: Grant Permissions
- Go to **Administration → Profiles**
- Select a technician profile
- Scroll to "Appointment Manager plugin"
- Grant rights:
  - **Manage appointments**: Read/Create/Update/Delete for proposal and status changes
  - **Manage appointment types**: Read/Update for type selection
  - **Manage own availability**: Read/Update for availability scheduling
- Click **Save**

## Usage

### Creating an Appointment

1. **Open a ticket** in GLPI
2. In the **Follow-up** tab, click **Propose appointment**
3. A modal opens with:
   - **Mini calendar**: Click or drag to select start/end time
   - **Appointment type**: Choose from configured types
   - **Start/End times**: Auto-filled from calendar selection, editable
   - **Location**: Optional location details
4. Click **Propose** to create the appointment
5. A follow-up is posted with action buttons for the requester/tech

### Confirming, Declining, or Rescheduling

- **Confirm**: Requester/tech clicks the "Confirm" button in the follow-up
  - Appointment status changes to "Confirmed"
  - Button is removed (cannot confirm twice)
- **Decline**: Requester/tech clicks "Decline"
  - Appointment status changes to "Declined"
  - Tech can propose a new one
- **Request reschedule**: Either party clicks "Request reschedule"
  - A calendar opens for selecting a new time
  - A new proposed appointment is created with the same ticket/tech/type
  - Original appointment remains (status → "Reschedule requested")

### Updating an Appointment

1. Open the ticket
2. If an active appointment exists (Proposed/Confirmed/Reschedule requested), the button changes to **Update appointment**
3. Click it to edit dates, type, or location
4. Changes reset the appointment to "Proposed" and post a follow-up

### Checking External Calendar Availability

- When proposing or updating an appointment, the mini calendar displays:
  - **Blocked periods** as gray background blocks
  - **External calendar events** (Google/Outlook) as gray busy slots
- This helps avoid double-booking

### Managing Blocked Periods

1. Go to **Setup → Appointment Manager → Blocked Periods**
2. Click **Add blocked period**
3. Enter:
   - **Start/End dates and times**
   - **Reason** (e.g., "PTO", "Training", optional)
4. Click **Save**
4. Blocked periods appear on the mini calendar for all technicians viewing appointments

## Security Notes

- **OAuth tokens** are encrypted and stored in the database; never exposed in logs
- **CSRF protection** is enabled by default (GLPI framework auto-validates all POST requests)
- **Permissions** are enforced at the profile level; users cannot see appointments outside their visibility scope
- **URL escaping** is applied to all user-supplied data in HTML contexts
- **SQL queries** use parameterized queries via GLPI's `$DB->request()` API
- **Calendar integrations** use OAuth 2.0 with state validation; external API failures never break the main GLPI flow

## Database Schema

| Table | Purpose |
|-------|---------|
| `glpi_plugin_appointmentmanager_appointments` | Appointment records (ticket, tech, dates, type, status) |
| `glpi_plugin_appointmentmanager_types` | Appointment type definitions (name, color, active) |
| `glpi_plugin_appointmentmanager_availability` | Weekly availability windows per technician |
| `glpi_plugin_appointmentmanager_blocked_periods` | Blocked time ranges per technician |
| `glpi_plugin_appointmentmanager_oauth_settings` | OAuth credentials per provider (Google, Microsoft) |
| `glpi_plugin_appointmentmanager_oauth_tokens` | User auth tokens per provider (encrypted access/refresh tokens) |
| `glpi_plugin_appointmentmanager_external_events` | External calendar event ID mapping (for sync tracking) |

## Troubleshooting

### "Table ... doesn't exist" errors
- **Cause**: Migration didn't run (tables not created)
- **Fix**: Go to **Setup → Plugins**, find "Appointment Manager", click **Reinstall**

### OAuth "redirect_uri mismatch" errors
- **Cause**: Redirect URI in plugin config doesn't match the one registered in Google/Microsoft console
- **Fix**: Check that the domain and path are exactly correct (HTTPS required, trailing slash matters)

### Calendar events not syncing
- **Cause**: User hasn't connected their calendar, or token expired
- **Fix**: Go to **Setup → Appointment Manager → Calendar Integrations** and click **Disconnect**, then **Connect** to re-authorize
- Check plugin error logs: `grep appointmentmanager /var/log/php.log`

### Buttons stay after action
- If you see appointment action buttons (Confirm/Decline/Reschedule) after taking an action, refresh the ticket page
- The follow-up is updated server-side; a page refresh syncs the view

### External calendar events not showing
- Ensure the user has authorized calendar access in **Setup → Appointment Manager → Calendar Integrations**
- Check that availability is set for the technician (external events only show for their configured hours)
- Verify the external calendar has events in the selected date range

## Development

### Project Structure
```
appointmentmanager/
├── setup.php                        # Plugin metadata, hooks
├── hook.php                         # Database migration (install/uninstall)
├── inc/
│   ├── appointment.class.php        # Core appointment logic
│   ├── appointmenttype.class.php    # Appointment type CRUD
│   ├── availability.class.php       # Weekly availability
│   ├── blockedperiod.class.php      # Blocked period management
│   ├── calendarsync.class.php       # Google/Microsoft sync orchestration
│   ├── googleprovider.class.php     # Google Calendar API v3
│   ├── microsoftprovider.class.php  # Microsoft Graph API
│   ├── oauthprovider.class.php      # Abstract OAuth base class
│   └── profile.class.php            # Permission/rights integration
├── front/
│   ├── appointment.form.php         # Appointment create/update form
│   ├── action.php                   # Public token-based actions (confirm/decline)
│   ├── reschedule.form.php          # Reschedule calendar flow
│   ├── config.php                   # Admin config UI (types, availability, sync)
│   ├── oauth.php                    # OAuth authorization redirect
│   └── oauth_callback.php           # OAuth callback (state validation, token exchange)
├── ajax/
│   └── events.php                   # FullCalendar event endpoint
├── locales/
│   └── [locale].po / .mo            # Translation files
└── README.md
```

### Coding Standards

- **SQL**: Use `$DB->request()` parameterized queries exclusively
- **Output**: Escape all user data with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`
- **Auth**: Always check `Session::haveRight()` or `Session::checkLoginUser()`
- **Error handling**: Wrap external API calls in `try/catch (Throwable $e)` with logging
- **Database**: Guard table existence checks with `$DB->tableExists()`

### Testing

No automated test suite. Testing is manual via a GLPI instance:

1. Propose an appointment and verify buttons appear
2. Confirm/decline and verify buttons are replaced with status badge
3. Request reschedule and verify new appointment is created
4. Connect Google/Outlook and verify sync works bidirectionally
5. Test across different technician profiles and permission levels

## License

[Specify your license here, e.g., GPL-3.0]

## Support

For issues, questions, or feature requests, contact the development team.
