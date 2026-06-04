-- Add receptionist to portal_role enum (run once in phpMyAdmin)
ALTER TABLE `users`
  MODIFY `portal_role` ENUM(
    'admin','hr','recruiter','management','training',
    'agent','receptionist','analytics','attendance'
  ) NOT NULL DEFAULT 'recruiter';

-- Optional: migrate legacy agent users to receptionist
-- UPDATE users SET portal_role = 'receptionist' WHERE portal_role = 'agent';
