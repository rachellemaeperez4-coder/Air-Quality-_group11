-- Run after supabase-admin-core.sql to allow Staff to delete stored readings.
begin;
grant delete on public.air_quality_readings to authenticated;
drop policy if exists aqm_staff_delete_readings on public.air_quality_readings;
create policy aqm_staff_delete_readings on public.air_quality_readings
  for delete to authenticated
  using (public.aqm_is_active_staff());
commit;
