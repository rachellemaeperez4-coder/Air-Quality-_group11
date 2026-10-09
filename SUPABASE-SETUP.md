# Supabase users setup

Run supabase-users-migration.sql in the Supabase SQL Editor. This replaces the
old aqm_profiles integration with your existing public.users table. Do not run
the old supabase-setup.sql for this integration.

The migration keeps the integer user_id and adds auth_user_id (UUID) to link
Supabase Auth. It makes password nullable: no password or password hash is copied
to this table. Existing rows and password values are not deleted by the migration.
Name/email are copied from Auth; all new and backfilled accounts receive User.
Existing linked roles remain unchanged. Staff promotion is an administrator action.

Accounts already in Authentication > Users are backfilled. If existing users
rows have matching emails but no Auth link, the migration stops and rolls back
instead of linking potentially privileged accounts by email. Verify ownership
before linking those rows. Share the SQL error if this happens.

The React login and Node API use public.users. Run this migration before testing the deployed application. For local development, use the Vite frontend URL (http://localhost:5173) as the Supabase Auth Site URL and configure email delivery before testing email confirmation.

The React frontend stores the Supabase Auth session in the browser. Protected Node API routes verify the Auth user, current role, and account status on each request.

For the staff console, run supabase-readings-access.sql in the Supabase SQL
Editor after the users migration. It preserves read-only access for User
accounts, allows Staff to add/update/delete rows in public.sensors, and allows
Staff to delete individual rows from public.air_quality_readings. The staff
dashboard uses the existing sensors table schema and displays the latest 100
readings with explicit delete confirmation. Sensor deletion may be rejected by
the database if related records prevent it; resolve those relationships rather
than bypassing the constraint. Use HTTPS when deploying.

The Staff SQL is split by module. Run supabase-admin-core.sql after the users
and readings-access migrations. Core adds the required Active/Disabled account
status column to public.users and shared permission checks.

Run only the feature SQL you need, one file at a time in Supabase SQL Editor:

- supabase-admin-zones.sql — Staff CRUD for the existing public.zones table.
- supabase-admin-devices.sql — Staff CRUD for public.devices.
- supabase-admin-sensors.sql — Staff CRUD for public.sensors.
- supabase-admin-readings.sql — Staff deletion of historical readings.
- supabase-admin-accounts.sql — Staff role and Active/Disabled controls.
- supabase-admin-alerts.sql — Staff status changes and air-quality alert
  generation/backfill in the existing public.alerts table. It logs a new alert
  only when the reading exceeds the previous alert peak of that severity during
  the current elevated episode; GOOD ends the episode. It does not create an
  `aqm_alerts` table. Run after supabase-admin-core.sql and
  supabase-esp32-ingest.sql.
- supabase-admin-threshold-settings.sql — Stores the shared Good, Moderate, and
  Hazardous maximums (initially 300, 350, and 500), adds the Staff-only update action,
  allows ESP32 devices to read the public limits, and updates the alert trigger
  to use the saved values. Run after supabase-admin-core.sql and
  supabase-admin-alerts.sql.
- supabase-admin-hazardous-threshold.sql — Ensures the adjustable Hazardous
  maximum exists; readings above it become Very Hazardous and create matching
  alerts. Run after supabase-admin-threshold-settings.sql. The initial Hazardous
  maximum is 500. It supports legacy readings tables with no sensor_id column;
  alert episodes are grouped by device/zone until sensor IDs are available.
- supabase-public-latest-readings.sql — Adds a restricted public RPC for the
  homepage. It returns only the latest MQ-135 value/status and zone label for up
  to three zones, not the complete readings table. Run after
  supabase-admin-hazardous-threshold.sql.
- supabase-admin-device-tokens.sql — Staff can enable/disable records in the
  existing public.aqm_device_tokens table. Run after supabase-esp32-ingest.sql.
- supabase-esp32-device-token.sql — Generates and stores a token hash for the
  chosen ESP32 device and displays the raw token once. Set its device_id before
  running; copy the result directly into the local sketch without sharing or
  committing it. Running it again rotates that device's token.

After running both threshold SQL files, Staff can adjust the Good, Moderate,
and Hazardous maximums from Staff > Thresholds. Valid limits are whole numbers
from 0 to 600, in increasing order. Website classifications, new Supabase
alerts, and both ESP32 sketches use the saved limits. The four ranges default
to Good 0–300, Moderate 301–350, Hazardous 351–500, and Very Hazardous 501–600.
Connected ESP32 sketches fetch updates while online and keep the last valid
values (or these defaults before the first successful fetch) while offline.
Upload both matching sketch copies after updating firmware. The Alert history
page reads the existing alerts table; a general-purpose audit log is not
included because there is no audit table in the current project schema.

Both threshold migrations are required by the updated website. Run
supabase-admin-threshold-settings.sql after supabase-admin-alerts.sql, then run
supabase-admin-hazardous-threshold.sql. Until both are installed, pages that
classify air quality will show a setup error rather than silently using stale
limits.

The public homepage preview reads live readings through
supabase-public-latest-readings.sql. Run it after the threshold migrations.
Only a bounded latest reading per zone is exposed to unauthenticated visitors;
the raw readings table remains restricted to signed-in accounts.
Supabase SQL Editor showing “Success. No rows returned” after creating this
function is expected. If the homepage reports that
`aqm_threshold_settings` is missing, first run
supabase-admin-threshold-settings.sql and
supabase-admin-hazardous-threshold.sql, then re-run
supabase-public-latest-readings.sql to refresh the function in the API schema.

The alert trigger only creates Moderate, Hazardous, or Very Hazardous alerts when a reading has
zone_id/device_id/sensor_id values. Repeated readings at or below the previous
alert peak do not create duplicates; readings above that peak create a new alert.
GOOD readings resolve active alerts for that sensor and begin a fresh episode.
The trigger never inserts synthetic readings. Keep SQL in the Supabase SQL
Editor; never expose a service-role key. The Node API uses the publishable key together with the signed-in user access token and relies on row-level security.

The React Staff console has routes for overview, alerts, devices, sensors, accounts, thresholds, readings, and alert history. The Node API uses the signed-in Staff access token for database operations. Staff can edit zones, devices, and sensors; change alert status; manage readings; promote or disable linked accounts; send password-reset emails through Supabase Auth; and export readings to CSV. Device tokens remain in Supabase for ESP32 upload authentication and are not managed through the Staff Console. Auth account creation remains in Supabase Auth; the management page does not create passwords and the app does not require a service-role credential.

Account disablement is checked by the Node API and shared database read policies. Run supabase-admin-core.sql before deploying the React app and API.

For ESP32 readings to reach Supabase, use a real Wi-Fi-connected ESP32 (the
simulator mode cannot upload), set ENABLE_WIFI to true, and configure the Wi-Fi
credentials and an active raw device token for the matching device. Register
the MQ-2 under the device in Staff > Sensors and note its generated
sensor_id. Run supabase-esp32-ingest.sql to configure the readings table and
the MQ-2-only ingestion function, then run
supabase-admin-alerts.sql to install per-sensor alert tracking. Set SENSOR_ID
in the local sketch to the registered sensor_id. Set the device_id in
supabase-esp32-device-token.sql to the ESP32's existing public.devices ID, run
it once, then copy its one-time result into DEVICE_TOKEN.
Never use token_hash as the raw device token or share device tokens publicly.
ZONE_ID and DEVICE_ID must refer to existing linked rows. Connect the single
MQ-2 analog output to D34. After uploading the sketch, open Serial Monitor at
115200 baud and confirm it reports "Reading saved to Supabase." HTTP errors
and transport failures are printed there; failed writes are retried every five
seconds, while successful uploads continue at the configured interval. The
The sketch reads the MQ-2 analog output on D34. DHT22 readings are no longer
used or uploaded. The ingestion setup drops the unused `co_value`,
`temperature`, and `humidity` columns; existing values in those columns will
be permanently removed when you run the updated SQL in the Supabase SQL Editor.

If the readings table is still empty, check Serial Monitor first. A placeholder
device token is rejected; replace it locally with a valid active raw token
without sharing it. HTTP 401 indicates token/authentication trouble, HTTP 23503
indicates that the configured device is not linked to that zone (or an ID is
missing), and a 2xx response confirms the reading was accepted.
