-- Run this migration in Supabase SQL Editor as the project owner.
-- Existing public.users rows are preserved. Passwords stay in Supabase Auth.
begin;
lock table public.users in share row exclusive mode;
alter table public.users add column if not exists auth_user_id uuid
  references auth.users(id) on delete cascade;
create unique index if not exists users_auth_user_id_unique on public.users(auth_user_id);
alter table public.users alter column password drop not null;
alter table public.users alter column role set default 'User';

-- Do not attach old accounts by email automatically: an existing Staff row
-- must never be claimed by a new signup. Resolve conflicts manually first.
do $$
begin
  if exists (
    select 1 from public.users u join auth.users a on lower(u.email) = lower(a.email)
    where u.auth_user_id is null
  ) then
    raise exception 'Existing users rows match Auth emails. Verify ownership and link their auth_user_id manually, then rerun. No changes have been committed.';
  end if;
end;
$$;

create or replace function public.aqm_sync_user() returns trigger
language plpgsql security definer set search_path = '' as $$
begin
  if TG_OP = 'INSERT' then
    insert into public.users (auth_user_id, name, email, role)
    values (new.id, coalesce(nullif(btrim(new.raw_user_meta_data ->> 'name'), ''), split_part(new.email, '@', 1)), new.email, 'User');
  else
    update public.users set email = new.email,
      name = coalesce(nullif(btrim(new.raw_user_meta_data ->> 'name'), ''), name)
    where auth_user_id = new.id;
  end if;
  return new;
end;
$$;
revoke all on function public.aqm_sync_user() from public, anon, authenticated;
drop trigger if exists aqm_auth_user_created on auth.users;
drop trigger if exists aqm_users_sync on auth.users;
create trigger aqm_users_sync after insert or update of email, raw_user_meta_data
on auth.users for each row execute function public.aqm_sync_user();

-- Backfill accounts that were already created through Supabase Auth.
insert into public.users (auth_user_id, name, email, role)
select a.id, coalesce(nullif(btrim(a.raw_user_meta_data ->> 'name'), ''), split_part(a.email, '@', 1)), a.email, 'User'
from auth.users a where a.email is not null
and not exists (select 1 from public.users u where u.auth_user_id = a.id);

alter table public.users enable row level security;
revoke all on public.users from anon, authenticated;
grant select (user_id, auth_user_id, name, email, role, created_at) on public.users to authenticated;
drop policy if exists aqm_users_read_own on public.users;
drop policy if exists aqm_users_restrict_own on public.users;
create policy aqm_users_read_own on public.users for select to authenticated
using ((select auth.uid()) = auth_user_id);
-- Restrict any older permissive policies as well.
create policy aqm_users_restrict_own on public.users as restrictive for all to authenticated
using ((select auth.uid()) = auth_user_id) with check (false);
notify pgrst, 'reload schema';
commit;

-- Verify in Table Editor: users now contains auth_user_id, name, email, User.
-- Existing roles are preserved. New/backfilled accounts are always User.
-- After verifying an account, an administrator may assign Staff separately:
-- update public.users set role = 'Staff' where auth_user_id = 'AUTH-USER-UUID';
