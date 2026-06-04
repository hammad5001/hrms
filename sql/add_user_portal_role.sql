ALTER TABLE `users`
  MODIFY `portal_role` ENUM(
    'admin','hr','recruiter','management','training',
    'agent','receptionist','user','analytics','attendance'
  ) NOT NULL DEFAULT 'user';
