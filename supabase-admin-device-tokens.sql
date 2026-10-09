-- Run after supabase-admin-core.sql and a successful supabase-esp32-ingest.sql.
-- This grants Active Staff permission to read token status and toggle active.
-- It does not depend on a specific device ID or expose token hashes.
begin;

alter table public.aqm_device_tokens enable row level security;
revoke all on public.aqm_device_tokens from anon, authenticated;
grant select (device_id, active, created_at), update (active)
  on public.aqm_device_tokens to authenticated;

drop policy if exists aqm_staff_manage_device_token_state on public.aqm_device_tokens;
create policy aqm_staff_manage_device_token_state on public.aqm_device_tokens
  for all to authenticated
  using (public.aqm_is_active_staff())
  with check (public.aqm_is_active_staff());

drop policy if exists aqm_staff_restrict_device_tokens on public.aqm_device_tokens;
create policy aqm_staff_restrict_device_tokens on public.aqm_device_tokens as restrictive
  for all to authenticated
  using (public.aqm_is_active_staff())
  with check (public.aqm_is_active_staff());

notify pgrst, 'reload schema';
commit;
