-- Run after supabase-users-migration.sql and supabase-admin-core.sql.
-- Stores non-sensitive user activity events for the UserLogs page.
begin;

create table if not exists public.user_activity_logs (
  log_id bigint generated always as identity primary key,
  user_id integer references public.users(user_id) on delete set null,
  auth_user_id uuid references auth.users(id) on delete set null,
  activity_type text not null
    check (char_length(btrim(activity_type)) between 1 and 80),
  description text
    check (description is null or char_length(description) <= 300),
  created_at timestamptz not null default now()
);

create index if not exists user_activity_logs_user_created_idx
  on public.user_activity_logs (auth_user_id, created_at desc);
create index if not exists user_activity_logs_created_idx
  on public.user_activity_logs (created_at desc);

alter table public.user_activity_logs enable row level security;
revoke all on public.user_activity_logs from anon, authenticated;
grant select on public.user_activity_logs to authenticated;
grant insert (user_id, auth_user_id, activity_type, description)
  on public.user_activity_logs to authenticated;

drop policy if exists aqm_activity_logs_read_own_or_staff
  on public.user_activity_logs;
create policy aqm_activity_logs_read_own_or_staff
  on public.user_activity_logs for select to authenticated
  using (
    (
      auth_user_id = (select auth.uid())
      and public.aqm_is_active_account()
    )
    or public.aqm_is_active_staff()
  );

drop policy if exists aqm_activity_logs_insert_own
  on public.user_activity_logs;
create policy aqm_activity_logs_insert_own
  on public.user_activity_logs for insert to authenticated
  with check (
    auth_user_id = (select auth.uid())
    and public.aqm_is_active_account()
    and exists (
      select 1
      from public.users u
      where u.user_id = user_activity_logs.user_id
        and u.auth_user_id = (select auth.uid())
    )
  );

notify pgrst, 'reload schema';
commit;

-- The UserLogs page/backend must insert events explicitly; creating this table
-- alone does not automatically log activity. Never store passwords, tokens,
-- SMTP credentials, device tokens, or other secrets in activity descriptions.
