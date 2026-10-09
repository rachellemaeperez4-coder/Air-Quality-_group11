-- Run once in the Supabase SQL Editor. Uses a dedicated table to avoid
-- changing any existing profiles table in your project.
begin;
create table public.aqm_profiles (
  id uuid primary key references auth.users(id) on delete cascade,
  role text not null default 'user' check (role in ('user', 'staff'))
);
alter table public.aqm_profiles enable row level security;
revoke all on public.aqm_profiles from anon, authenticated;
grant select on public.aqm_profiles to authenticated;
create policy "Read own AQM role" on public.aqm_profiles for select to authenticated
using ((select auth.uid()) = id);
create function public.aqm_create_profile() returns trigger
language plpgsql security definer set search_path = '' as $$
begin
  insert into public.aqm_profiles (id, role) values (new.id, 'user');
  return new;
end;
$$;
revoke all on function public.aqm_create_profile() from public, anon, authenticated;
create trigger aqm_auth_user_created after insert on auth.users
for each row execute procedure public.aqm_create_profile();
insert into public.aqm_profiles (id, role) select id, 'user' from auth.users;
commit;

-- To promote a known account, run this separately after replacing the email:
-- update public.aqm_profiles set role = 'staff'
-- where id = (select id from auth.users where email = 'staff@example.com');
