-- ============================================================================
--  MIGRATIONS/SEED_50_MORE.SQL
--
--    mysql -u root registrar_ai < migrations/seed_50_more.sql
--    phpMyAdmin: Import > choose this file.
--
--  NO TRANSACTION ON PURPOSE
--  ------------------------
--  phpMyAdmin wraps an import in a transaction. A second START TRANSACTION
--  raises MySQL 1568 and phpMyAdmin aborts the entire file with a generic
--  "import could not be completed". phpMyAdmin commits for us; the mysql
--  client commits by default. Nothing is lost by leaving it alone.
--
--  TWO MARKERS, BOTH REQUIRED
--  -----------------------
--    students     student_number LIKE 'S27%'   AND  email LIKE '%@testdata.example'
--    enrollments  email LIKE '%@seed.receive.test'
--
--  One marker is not enough. student_number carries only a non-unique index,
--  so a real student could eventually be issued an S27xxxx number.
--  SAFE TO RUN TWICE
--  -----------------
--  The rows below carry explicit ids, so a second import would collide on
--  the primary key. This clears any previous run first, which also makes it
--  the fix for an import that died halfway through.

--  Children before parents: academic_grades has no declared foreign key
--  today, so the order is not left to chance. Delete a student first and its
--  grades are orphaned - rows the Academic History board renders as a
--  cohort nobody can place.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
DELETE g FROM academic_grades g
  JOIN academic_history h ON h.id = g.academic_history_id
  JOIN students s ON s.id = h.student_id
 WHERE s.student_number LIKE 'S27%' AND s.email LIKE '%@testdata.example';

DELETE h FROM academic_history h
  JOIN students s ON s.id = h.student_id
 WHERE s.student_number LIKE 'S27%' AND s.email LIKE '%@testdata.example';

DELETE u FROM users u
  JOIN students s ON s.id = u.student_id
 WHERE s.student_number LIKE 'S27%' AND s.email LIKE '%@testdata.example';

DELETE FROM students WHERE student_number LIKE 'S27%' AND email LIKE '%@testdata.example';

DELETE FROM enrollments WHERE email LIKE '%@seed.receive.test';

-- ── Students ──────────────────────────────────────────────────────────
INSERT INTO `students` (
  `id`, `student_number`, `first_name`, `middle_name`, `last_name`, `gender`, `civil_status`, `birth_date`, `nationality`, `address`, `contact_number`, `email`, `course`, `year_level`, `school_year`, `semester`, `section`, `status`, `graduation_date`, `school_year_graduated`, `email_is_placeholder`
) VALUES
  (1457, 'S270001', 'Noel', 'S', 'Abad', 'Male', 'Single', '2001-01-01', 'Filipino', 'SEEDDATA- Abad, Quezon City', '09100810003', 'noel.abad.s270001@testdata.example', 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)', 1, '2026-2027', '1st', '11001', 'enrolled', NULL, NULL, 1),
  (1458, 'S270002', 'Rhea', 'R', 'Diaz', 'Female', 'Single', '2001-01-01', 'Filipino', 'SEEDDATA- Diaz, Quezon City', '09100810006', 'rhea.diaz.s270002@testdata.example', 'BACHELOR OF SCIENCE IN COMPUTER ENGINEERING (BSCpE)', 1, '2024-2025', 'Summer', '13002', 'alumni', '0000-00-00', '2024-2025', 1),
  (1459, 'S270003', 'Erick', 'C', 'Gatmaitan', 'Male', 'Single', '2001-01-01', 'Filipino', 'SEEDDATA- Gatmaitan, Quezon City', '09100810009', 'erick.gatmaitan.s270003@testdata.example', 'BACHELOR OF SCIENCE IN ACCOUNTING INFORMATION SYSTEM (BSAIS)', 1, '2025-2026', '2nd', '12003', 'active', NULL, NULL, 1),
  (1460, 'S270004', 'Marilen', 'M', 'Javier', 'Female', 'Single', '2001-01-01', 'Filipino', 'SEEDDATA- Javier, Quezon City', '09100810012', 'marilen.javier.s270004@testdata.example', 'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION (BSBA)', 1, '2026-2027', '1st', '11001', 'enrolled', NULL, NULL, 1),
  (1461, 'S270005', 'Rogelio', 'A', 'Nolasco', 'Male', 'Single', '2001-01-01', 'Filipino', 'SEEDDATA- Nolasco, Quezon City', '09100810015', 'rogelio.nolasco.s270005@testdata.example', 'BACHELOR OF SCIENCE IN TOURISM MANAGEMENT (BSTM)', 1, '2024-2025', 'Summer', '13002', 'dropped', NULL, NULL, 1),
  (1462, 'S270006', 'Jhoanna', 'P', 'Roxas II', 'Female', 'Single', '2001-01-01', 'Filipino', 'SEEDDATA- Roxas II, Quezon City', '09100810018', 'jhoanna.roxas.ii.s270006@testdata.example', 'BACHELOR OF SCIENCE IN PSYCHOLOGY (BSP)', 1, '2025-2026', '2nd', '12003', 'graduate', '0000-00-00', '2025-2026', 1),
  (1463, 'S270007', 'Arnel', '', 'Uy', 'Male', 'Single', '2002-01-01', 'Filipino', 'SEEDDATA- Uy, Quezon City', '09100810021', 'arnel.uy.s270007@testdata.example', 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT (BSHM)', 2, '2026-2027', '1st', '21001', 'active', NULL, NULL, 1),
  (1464, 'S270008', 'Kaye', '', 'Zamora', 'Female', 'Single', '2002-01-01', 'Filipino', 'SEEDDATA- Zamora, Quezon City', '09100810024', 'kaye.zamora.s270008@testdata.example', 'BACHELOR OF SCIENCE IN OFFICE ADMINISTRATION (BSOA)', 2, '2024-2025', 'Summer', '23002', 'dropped', NULL, NULL, 1),
  (1465, 'S270009', 'Baldwin', 'S', 'Cordero', 'Male', 'Single', '2002-01-01', 'Filipino', 'SEEDDATA- Cordero, Quezon City', '09100810027', 'baldwin.cordero.s270009@testdata.example', 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)', 2, '2025-2026', '2nd', '22003', 'graduate', '0000-00-00', '2025-2026', 1),
  (1466, 'S270010', 'Liezl', 'R', 'Fajardo', 'Female', 'Single', '2002-01-01', 'Filipino', 'SEEDDATA- Fajardo, Quezon City', '09100810030', 'liezl.fajardo.s270010@testdata.example', 'BACHELOR OF SCIENCE IN COMPUTER ENGINEERING (BSCpE)', 2, '2026-2027', '1st', '21001', 'active', NULL, NULL, 1),
  (1467, 'S270011', 'Crisanto', 'C', 'Ignacio', 'Male', 'Single', '2003-01-01', 'Filipino', 'SEEDDATA- Ignacio, Quezon City', '09100810033', 'crisanto.ignacio.s270011@testdata.example', 'BACHELOR OF SCIENCE IN ACCOUNTING INFORMATION SYSTEM (BSAIS)', 3, '2024-2025', 'Summer', '33002', 'enrolled', NULL, NULL, 1),
  (1468, 'S270012', 'Maegan', 'M', 'Magsaysay', 'Female', 'Single', '2003-01-01', 'Filipino', 'SEEDDATA- Magsaysay, Quezon City', '09100810036', 'maegan.magsaysay.s270012@testdata.example', 'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION (BSBA)', 3, '2025-2026', '2nd', '32003', 'alumni', '0000-00-00', '2025-2026', 1),
  (1469, 'S270013', 'Dante', 'A', 'Quiambao', 'Male', 'Single', '2003-01-01', 'Filipino', 'SEEDDATA- Quiambao, Quezon City', '09100810039', 'dante.quiambao.s270013@testdata.example', 'BACHELOR OF SCIENCE IN TOURISM MANAGEMENT (BSTM)', 3, '2026-2027', '1st', '31001', 'active', NULL, NULL, 1),
  (1470, 'S270014', 'Nina', 'P', 'Tolentino', 'Female', 'Single', '2004-01-01', 'Filipino', 'SEEDDATA- Tolentino, Quezon City', '09100810042', 'nina.tolentino.s270014@testdata.example', 'BACHELOR OF SCIENCE IN PSYCHOLOGY (BSP)', 4, '2024-2025', 'Summer', '43002', 'enrolled', NULL, NULL, 1),
  (1471, 'S270015', 'Erwin', '', 'Yulo', 'Male', 'Single', '2004-01-01', 'Filipino', 'SEEDDATA- Yulo, Quezon City', '09100810045', 'erwin.yulo.s270015@testdata.example', 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT (BSHM)', 4, '2025-2026', '2nd', '42003', 'dropped', NULL, NULL, 1),
  (1472, 'S270016', 'Irish', '', 'Basco', 'Female', 'Single', '2001-01-01', 'Filipino', 'SEEDDATA- Basco, Quezon City', '09100810048', 'irish.basco.s270016@testdata.example', 'BACHELOR OF SCIENCE IN OFFICE ADMINISTRATION (BSOA)', 1, '2026-2027', '1st', '11001', 'graduate', '0000-00-00', '2026-2027', 1),
  (1473, 'S270017', 'Noel', 'S', 'Evangelista', 'Male', 'Single', '2001-01-01', 'Filipino', 'SEEDDATA- Evangelista, Quezon City', '09100810051', 'noel.evangelista.s270017@testdata.example', 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)', 1, '2024-2025', 'Summer', '13002', 'active', NULL, NULL, 1),
  (1474, 'S270018', 'Rhea', 'R', 'Hernandez', 'Female', 'Single', '2001-01-01', 'Filipino', 'SEEDDATA- Hernandez, Quezon City', '09100810054', 'rhea.hernandez.s270018@testdata.example', 'BACHELOR OF SCIENCE IN COMPUTER ENGINEERING (BSCpE)', 1, '2025-2026', '2nd', '12003', 'dropped', NULL, NULL, 1),
  (1475, 'S270019', 'Erick', 'C', 'Lazaro', 'Male', 'Single', '2001-01-01', 'Filipino', 'SEEDDATA- Lazaro, Quezon City', '09100810057', 'erick.lazaro.s270019@testdata.example', 'BACHELOR OF SCIENCE IN ACCOUNTING INFORMATION SYSTEM (BSAIS)', 1, '2026-2027', '1st', '11001', 'graduate', '0000-00-00', '2026-2027', 1),
  (1476, 'S270020', 'Marilen', 'M', 'Pangilinan', 'Female', 'Single', '2001-01-01', 'Filipino', 'SEEDDATA- Pangilinan, Quezon City', '09100810060', 'marilen.pangilinan.s270020@testdata.example', 'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION (BSBA)', 1, '2024-2025', 'Summer', '13002', 'active', NULL, NULL, 1),
  (1477, 'S270021', 'Rogelio', 'A', 'Sison', 'Male', 'Single', '2002-01-01', 'Filipino', 'SEEDDATA- Sison, Quezon City', '09100810063', 'rogelio.sison.s270021@testdata.example', 'BACHELOR OF SCIENCE IN TOURISM MANAGEMENT (BSTM)', 2, '2025-2026', '2nd', '22003', 'enrolled', NULL, NULL, 1),
  (1478, 'S270022', 'Jhoanna', 'P', 'Valencia', 'Female', 'Single', '2002-01-01', 'Filipino', 'SEEDDATA- Valencia, Quezon City', '09100810066', 'jhoanna.valencia.s270022@testdata.example', 'BACHELOR OF SCIENCE IN PSYCHOLOGY (BSP)', 2, '2026-2027', '1st', '21001', 'alumni', '0000-00-00', '2026-2027', 1),
  (1479, 'S270023', 'Arnel', '', 'Abad', 'Male', 'Single', '2002-01-01', 'Filipino', 'SEEDDATA- Abad, Quezon City', '09100810069', 'arnel.abad.s270023@testdata.example', 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT (BSHM)', 2, '2024-2025', 'Summer', '23002', 'active', NULL, NULL, 1),
  (1480, 'S270024', 'Kaye', '', 'Diaz', 'Female', 'Single', '2002-01-01', 'Filipino', 'SEEDDATA- Diaz, Quezon City', '09100810072', 'kaye.diaz.s270024@testdata.example', 'BACHELOR OF SCIENCE IN OFFICE ADMINISTRATION (BSOA)', 2, '2025-2026', '2nd', '22003', 'enrolled', NULL, NULL, 1),
  (1481, 'S270025', 'Baldwin', 'S', 'Gatmaitan', 'Male', 'Single', '2002-01-01', 'Filipino', 'SEEDDATA- Gatmaitan, Quezon City', '09100810075', 'baldwin.gatmaitan.s270025@testdata.example', 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)', 2, '2026-2027', '1st', '21001', 'dropped', NULL, NULL, 1),
  (1482, 'S270026', 'Liezl', 'R', 'Javier', 'Female', 'Single', '2003-01-01', 'Filipino', 'SEEDDATA- Javier, Quezon City', '09100810078', 'liezl.javier.s270026@testdata.example', 'BACHELOR OF SCIENCE IN COMPUTER ENGINEERING (BSCpE)', 3, '2024-2025', 'Summer', '33002', 'graduate', '0000-00-00', '2024-2025', 1),
  (1483, 'S270027', 'Crisanto', 'C', 'Nolasco', 'Male', 'Single', '2003-01-01', 'Filipino', 'SEEDDATA- Nolasco, Quezon City', '09100810081', 'crisanto.nolasco.s270027@testdata.example', 'BACHELOR OF SCIENCE IN ACCOUNTING INFORMATION SYSTEM (BSAIS)', 3, '2025-2026', '2nd', '32003', 'active', NULL, NULL, 1),
  (1484, 'S270028', 'Maegan', 'M', 'Roxas II', 'Female', 'Single', '2003-01-01', 'Filipino', 'SEEDDATA- Roxas II, Quezon City', '09100810084', 'maegan.roxas.ii.s270028@testdata.example', 'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION (BSBA)', 3, '2026-2027', '1st', '31001', 'dropped', NULL, NULL, 1),
  (1485, 'S270029', 'Dante', 'A', 'Uy', 'Male', 'Single', '2004-01-01', 'Filipino', 'SEEDDATA- Uy, Quezon City', '09100810087', 'dante.uy.s270029@testdata.example', 'BACHELOR OF SCIENCE IN TOURISM MANAGEMENT (BSTM)', 4, '2024-2025', 'Summer', '43002', 'graduate', '0000-00-00', '2024-2025', 1),
  (1486, 'S270030', 'Nina', 'P', 'Zamora', 'Female', 'Single', '2001-01-01', 'Filipino', 'SEEDDATA- Zamora, Quezon City', '09100810090', 'nina.zamora.s270030@testdata.example', 'BACHELOR OF SCIENCE IN PSYCHOLOGY (BSP)', 1, '2025-2026', '2nd', '12003', 'active', NULL, NULL, 1),
  (1487, 'S270031', 'Erwin', '', 'Cordero', 'Male', 'Single', '2001-01-01', 'Filipino', 'SEEDDATA- Cordero, Quezon City', '09100810093', 'erwin.cordero.s270031@testdata.example', 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT (BSHM)', 1, '2026-2027', '1st', '11001', 'enrolled', NULL, NULL, 1),
  (1488, 'S270032', 'Irish', '', 'Fajardo', 'Female', 'Single', '2001-01-01', 'Filipino', 'SEEDDATA- Fajardo, Quezon City', '09100810096', 'irish.fajardo.s270032@testdata.example', 'BACHELOR OF SCIENCE IN OFFICE ADMINISTRATION (BSOA)', 1, '2024-2025', 'Summer', '13002', 'alumni', '0000-00-00', '2024-2025', 1),
  (1489, 'S270033', 'Noel', 'S', 'Ignacio', 'Male', 'Single', '2001-01-01', 'Filipino', 'SEEDDATA- Ignacio, Quezon City', '09100810099', 'noel.ignacio.s270033@testdata.example', 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)', 1, '2025-2026', '2nd', '12003', 'active', NULL, NULL, 1),
  (1490, 'S270034', 'Rhea', 'R', 'Magsaysay', 'Female', 'Single', '2001-01-01', 'Filipino', 'SEEDDATA- Magsaysay, Quezon City', '09100810102', 'rhea.magsaysay.s270034@testdata.example', 'BACHELOR OF SCIENCE IN COMPUTER ENGINEERING (BSCpE)', 1, '2026-2027', '1st', '11001', 'enrolled', NULL, NULL, 1),
  (1491, 'S270035', 'Erick', 'C', 'Quiambao', 'Male', 'Single', '2001-01-01', 'Filipino', 'SEEDDATA- Quiambao, Quezon City', '09100810105', 'erick.quiambao.s270035@testdata.example', 'BACHELOR OF SCIENCE IN ACCOUNTING INFORMATION SYSTEM (BSAIS)', 1, '2024-2025', 'Summer', '13002', 'dropped', NULL, NULL, 1),
  (1492, 'S270036', 'Marilen', 'M', 'Tolentino', 'Female', 'Single', '2002-01-01', 'Filipino', 'SEEDDATA- Tolentino, Quezon City', '09100810108', 'marilen.tolentino.s270036@testdata.example', 'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION (BSBA)', 2, '2025-2026', '2nd', '22003', 'graduate', '0000-00-00', '2025-2026', 1),
  (1493, 'S270037', 'Rogelio', 'A', 'Yulo', 'Male', 'Single', '2002-01-01', 'Filipino', 'SEEDDATA- Yulo, Quezon City', '09100810111', 'rogelio.yulo.s270037@testdata.example', 'BACHELOR OF SCIENCE IN TOURISM MANAGEMENT (BSTM)', 2, '2026-2027', '1st', '21001', 'active', NULL, NULL, 1),
  (1494, 'S270038', 'Jhoanna', 'P', 'Basco', 'Female', 'Single', '2002-01-01', 'Filipino', 'SEEDDATA- Basco, Quezon City', '09100810114', 'jhoanna.basco.s270038@testdata.example', 'BACHELOR OF SCIENCE IN PSYCHOLOGY (BSP)', 2, '2024-2025', 'Summer', '23002', 'dropped', NULL, NULL, 1),
  (1495, 'S270039', 'Arnel', '', 'Evangelista', 'Male', 'Single', '2002-01-01', 'Filipino', 'SEEDDATA- Evangelista, Quezon City', '09100810117', 'arnel.evangelista.s270039@testdata.example', 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT (BSHM)', 2, '2025-2026', '2nd', '22003', 'graduate', '0000-00-00', '2025-2026', 1),
  (1496, 'S270040', 'Kaye', '', 'Hernandez', 'Female', 'Single', '2003-01-01', 'Filipino', 'SEEDDATA- Hernandez, Quezon City', '09100810120', 'kaye.hernandez.s270040@testdata.example', 'BACHELOR OF SCIENCE IN OFFICE ADMINISTRATION (BSOA)', 3, '2026-2027', '1st', '31001', 'active', NULL, NULL, 1),
  (1497, 'S270041', 'Baldwin', 'S', 'Lazaro', 'Male', 'Single', '2003-01-01', 'Filipino', 'SEEDDATA- Lazaro, Quezon City', '09100810123', 'baldwin.lazaro.s270041@testdata.example', 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)', 3, '2024-2025', 'Summer', '33002', 'enrolled', NULL, NULL, 1),
  (1498, 'S270042', 'Liezl', 'R', 'Pangilinan', 'Female', 'Single', '2003-01-01', 'Filipino', 'SEEDDATA- Pangilinan, Quezon City', '09100810126', 'liezl.pangilinan.s270042@testdata.example', 'BACHELOR OF SCIENCE IN COMPUTER ENGINEERING (BSCpE)', 3, '2025-2026', '2nd', '32003', 'alumni', '0000-00-00', '2025-2026', 1),
  (1499, 'S270043', 'Crisanto', 'C', 'Sison', 'Male', 'Single', '2004-01-01', 'Filipino', 'SEEDDATA- Sison, Quezon City', '09100810129', 'crisanto.sison.s270043@testdata.example', 'BACHELOR OF SCIENCE IN ACCOUNTING INFORMATION SYSTEM (BSAIS)', 4, '2026-2027', '1st', '41001', 'active', NULL, NULL, 1),
  (1500, 'S270044', 'Maegan', 'M', 'Valencia', 'Female', 'Single', '2001-01-01', 'Filipino', 'SEEDDATA- Valencia, Quezon City', '09100810132', 'maegan.valencia.s270044@testdata.example', 'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION (BSBA)', 1, '2024-2025', 'Summer', '13002', 'enrolled', NULL, NULL, 1),
  (1501, 'S270045', 'Dante', 'A', 'Abad', 'Male', 'Single', '2001-01-01', 'Filipino', 'SEEDDATA- Abad, Quezon City', '09100810135', 'dante.abad.s270045@testdata.example', 'BACHELOR OF SCIENCE IN TOURISM MANAGEMENT (BSTM)', 1, '2025-2026', '2nd', '12003', 'dropped', NULL, NULL, 1),
  (1502, 'S270046', 'Nina', 'P', 'Diaz', 'Female', 'Single', '2001-01-01', 'Filipino', 'SEEDDATA- Diaz, Quezon City', '09100810138', 'nina.diaz.s270046@testdata.example', 'BACHELOR OF SCIENCE IN PSYCHOLOGY (BSP)', 1, '2026-2027', '1st', '11001', 'graduate', '0000-00-00', '2026-2027', 1),
  (1503, 'S270047', 'Erwin', '', 'Gatmaitan', 'Male', 'Single', '2001-01-01', 'Filipino', 'SEEDDATA- Gatmaitan, Quezon City', '09100810141', 'erwin.gatmaitan.s270047@testdata.example', 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT (BSHM)', 1, '2024-2025', 'Summer', '13002', 'active', NULL, NULL, 1),
  (1504, 'S270048', 'Irish', '', 'Javier', 'Female', 'Single', '2001-01-01', 'Filipino', 'SEEDDATA- Javier, Quezon City', '09100810144', 'irish.javier.s270048@testdata.example', 'BACHELOR OF SCIENCE IN OFFICE ADMINISTRATION (BSOA)', 1, '2025-2026', '2nd', '12003', 'dropped', NULL, NULL, 1),
  (1505, 'S270049', 'Noel', 'S', 'Nolasco', 'Male', 'Single', '2001-01-01', 'Filipino', 'SEEDDATA- Nolasco, Quezon City', '09100810147', 'noel.nolasco.s270049@testdata.example', 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)', 1, '2026-2027', '1st', '11001', 'graduate', '0000-00-00', '2026-2027', 1),
  (1506, 'S270050', 'Rhea', 'R', 'Roxas II', 'Female', 'Single', '2002-01-01', 'Filipino', 'SEEDDATA- Roxas II, Quezon City', '09100810150', 'rhea.roxas.ii.s270050@testdata.example', 'BACHELOR OF SCIENCE IN COMPUTER ENGINEERING (BSCpE)', 2, '2024-2025', 'Summer', '23002', 'active', NULL, NULL, 1);

-- ── Portal accounts (username = student_number, as resolveLoginUser expects) ─
--  One shared password: 'password'.
INSERT INTO `users` (
  `id`, `email`, `password_hash`, `full_name`, `role`, `student_id`, `username`, `is_active`
) VALUES
  (686, 'noel.abad.s270001@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Noel S Abad', 'student', 1457, 'S270001', 1),
  (687, 'rhea.diaz.s270002@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Rhea R Diaz', 'student', 1458, 'S270002', 1),
  (688, 'erick.gatmaitan.s270003@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Erick C Gatmaitan', 'student', 1459, 'S270003', 1),
  (689, 'marilen.javier.s270004@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Marilen M Javier', 'student', 1460, 'S270004', 1),
  (690, 'rogelio.nolasco.s270005@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Rogelio A Nolasco', 'student', 1461, 'S270005', 1),
  (691, 'jhoanna.roxas.ii.s270006@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Jhoanna P Roxas II', 'student', 1462, 'S270006', 1),
  (692, 'arnel.uy.s270007@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Arnel  Uy', 'student', 1463, 'S270007', 1),
  (693, 'kaye.zamora.s270008@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Kaye  Zamora', 'student', 1464, 'S270008', 1),
  (694, 'baldwin.cordero.s270009@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Baldwin S Cordero', 'student', 1465, 'S270009', 1),
  (695, 'liezl.fajardo.s270010@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Liezl R Fajardo', 'student', 1466, 'S270010', 1),
  (696, 'crisanto.ignacio.s270011@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Crisanto C Ignacio', 'student', 1467, 'S270011', 1),
  (697, 'maegan.magsaysay.s270012@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Maegan M Magsaysay', 'student', 1468, 'S270012', 1),
  (698, 'dante.quiambao.s270013@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Dante A Quiambao', 'student', 1469, 'S270013', 1),
  (699, 'nina.tolentino.s270014@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Nina P Tolentino', 'student', 1470, 'S270014', 1),
  (700, 'erwin.yulo.s270015@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Erwin  Yulo', 'student', 1471, 'S270015', 1),
  (701, 'irish.basco.s270016@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Irish  Basco', 'student', 1472, 'S270016', 1),
  (702, 'noel.evangelista.s270017@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Noel S Evangelista', 'student', 1473, 'S270017', 1),
  (703, 'rhea.hernandez.s270018@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Rhea R Hernandez', 'student', 1474, 'S270018', 1),
  (704, 'erick.lazaro.s270019@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Erick C Lazaro', 'student', 1475, 'S270019', 1),
  (705, 'marilen.pangilinan.s270020@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Marilen M Pangilinan', 'student', 1476, 'S270020', 1),
  (706, 'rogelio.sison.s270021@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Rogelio A Sison', 'student', 1477, 'S270021', 1),
  (707, 'jhoanna.valencia.s270022@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Jhoanna P Valencia', 'student', 1478, 'S270022', 1),
  (708, 'arnel.abad.s270023@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Arnel  Abad', 'student', 1479, 'S270023', 1),
  (709, 'kaye.diaz.s270024@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Kaye  Diaz', 'student', 1480, 'S270024', 1),
  (710, 'baldwin.gatmaitan.s270025@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Baldwin S Gatmaitan', 'student', 1481, 'S270025', 1),
  (711, 'liezl.javier.s270026@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Liezl R Javier', 'student', 1482, 'S270026', 1),
  (712, 'crisanto.nolasco.s270027@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Crisanto C Nolasco', 'student', 1483, 'S270027', 1),
  (713, 'maegan.roxas.ii.s270028@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Maegan M Roxas II', 'student', 1484, 'S270028', 1),
  (714, 'dante.uy.s270029@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Dante A Uy', 'student', 1485, 'S270029', 1),
  (715, 'nina.zamora.s270030@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Nina P Zamora', 'student', 1486, 'S270030', 1),
  (716, 'erwin.cordero.s270031@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Erwin  Cordero', 'student', 1487, 'S270031', 1),
  (717, 'irish.fajardo.s270032@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Irish  Fajardo', 'student', 1488, 'S270032', 1),
  (718, 'noel.ignacio.s270033@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Noel S Ignacio', 'student', 1489, 'S270033', 1),
  (719, 'rhea.magsaysay.s270034@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Rhea R Magsaysay', 'student', 1490, 'S270034', 1),
  (720, 'erick.quiambao.s270035@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Erick C Quiambao', 'student', 1491, 'S270035', 1),
  (721, 'marilen.tolentino.s270036@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Marilen M Tolentino', 'student', 1492, 'S270036', 1),
  (722, 'rogelio.yulo.s270037@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Rogelio A Yulo', 'student', 1493, 'S270037', 1),
  (723, 'jhoanna.basco.s270038@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Jhoanna P Basco', 'student', 1494, 'S270038', 1),
  (724, 'arnel.evangelista.s270039@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Arnel  Evangelista', 'student', 1495, 'S270039', 1),
  (725, 'kaye.hernandez.s270040@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Kaye  Hernandez', 'student', 1496, 'S270040', 1),
  (726, 'baldwin.lazaro.s270041@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Baldwin S Lazaro', 'student', 1497, 'S270041', 1),
  (727, 'liezl.pangilinan.s270042@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Liezl R Pangilinan', 'student', 1498, 'S270042', 1),
  (728, 'crisanto.sison.s270043@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Crisanto C Sison', 'student', 1499, 'S270043', 1),
  (729, 'maegan.valencia.s270044@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Maegan M Valencia', 'student', 1500, 'S270044', 1),
  (730, 'dante.abad.s270045@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Dante A Abad', 'student', 1501, 'S270045', 1),
  (731, 'nina.diaz.s270046@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Nina P Diaz', 'student', 1502, 'S270046', 1),
  (732, 'erwin.gatmaitan.s270047@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Erwin  Gatmaitan', 'student', 1503, 'S270047', 1),
  (733, 'irish.javier.s270048@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Irish  Javier', 'student', 1504, 'S270048', 1),
  (734, 'noel.nolasco.s270049@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Noel S Nolasco', 'student', 1505, 'S270049', 1),
  (735, 'rhea.roxas.ii.s270050@testdata.example', '$2y$10$AQoeA6B6gMstzOgpYLU0..PUz8T0bmSRa8aUtScQrBZ56XwFW7urG', 'Rhea R Roxas II', 'student', 1506, 'S270050', 1);

-- ── Term records ─────────────────────────────────────────────────────
--  gwa is the unit-weighted mean of the grades below.
INSERT INTO `academic_history` (
  `id`, `student_id`, `school_name`, `school_year`, `semester`, `grade_level`, `gwa`, `credits`, `subjects_completed`, `accepted_at`, `accepted_by`
) VALUES
  (601, 1457, 'Bestlink College of the Philippines', '2026-2027', '1st', 'Year 1', '1.41', '11.00', 4, '0000-00-00 00:00:00', 2),
  (602, 1458, 'Bestlink College of the Philippines', '2024-2025', 'Summer', 'Year 1', '2.51', '11.00', 4, '0000-00-00 00:00:00', 2),
  (603, 1459, 'Bestlink College of the Philippines', '2025-2026', '2nd', 'Year 1', '3.61', '11.00', 4, '0000-00-00 00:00:00', 2),
  (604, 1460, 'Bestlink College of the Philippines', '2026-2027', '1st', 'Year 1', '3.59', '11.00', 4, '0000-00-00 00:00:00', 2),
  (605, 1461, 'Bestlink College of the Philippines', '2024-2025', 'Summer', 'Year 1', '1.71', '11.00', 4, '0000-00-00 00:00:00', 2),
  (606, 1462, 'Bestlink College of the Philippines', '2025-2026', '2nd', 'Year 1', '2.81', '11.00', 4, '0000-00-00 00:00:00', 2),
  (607, 1463, 'Bestlink College of the Philippines', '2026-2027', '1st', 'Year 2', '4.00', '9.00', 3, '0000-00-00 00:00:00', 2),
  (608, 1464, 'Bestlink College of the Philippines', '2024-2025', 'Summer', 'Year 2', '2.37', '9.00', 3, '0000-00-00 00:00:00', 2),
  (609, 1465, 'Bestlink College of the Philippines', '2025-2026', '2nd', 'Year 2', '2.10', '9.00', 3, '0000-00-00 00:00:00', 2),
  (610, 1466, 'Bestlink College of the Philippines', '2026-2027', '1st', 'Year 2', '3.20', '9.00', 3, '0000-00-00 00:00:00', 2),
  (611, 1467, 'Bestlink College of the Philippines', '2024-2025', 'Summer', 'Year 3', '4.30', '9.00', 3, '0000-00-00 00:00:00', 2),
  (612, 1468, 'Bestlink College of the Philippines', '2025-2026', '2nd', 'Year 3', '2.67', '9.00', 3, '0000-00-00 00:00:00', 2),
  (613, 1469, 'Bestlink College of the Philippines', '2026-2027', '1st', 'Year 3', '2.40', '9.00', 3, '0000-00-00 00:00:00', 2),
  (614, 1470, 'Bestlink College of the Philippines', '2024-2025', 'Summer', 'Year 4', '3.21', '7.00', 2, '0000-00-00 00:00:00', 2),
  (615, 1471, 'Bestlink College of the Philippines', '2025-2026', '2nd', 'Year 4', '4.31', '7.00', 2, '0000-00-00 00:00:00', 2),
  (616, 1472, 'Bestlink College of the Philippines', '2026-2027', '1st', 'Year 1', '1.51', '11.00', 4, '0000-00-00 00:00:00', 2),
  (617, 1473, 'Bestlink College of the Philippines', '2024-2025', 'Summer', 'Year 1', '2.61', '11.00', 4, '0000-00-00 00:00:00', 2),
  (618, 1474, 'Bestlink College of the Philippines', '2025-2026', '2nd', 'Year 1', '3.71', '11.00', 4, '0000-00-00 00:00:00', 2),
  (619, 1475, 'Bestlink College of the Philippines', '2026-2027', '1st', 'Year 1', '3.69', '11.00', 4, '0000-00-00 00:00:00', 2),
  (620, 1476, 'Bestlink College of the Philippines', '2024-2025', 'Summer', 'Year 1', '1.81', '11.00', 4, '0000-00-00 00:00:00', 2),
  (621, 1477, 'Bestlink College of the Philippines', '2025-2026', '2nd', 'Year 2', '3.00', '9.00', 3, '0000-00-00 00:00:00', 2),
  (622, 1478, 'Bestlink College of the Philippines', '2026-2027', '1st', 'Year 2', '4.10', '9.00', 3, '0000-00-00 00:00:00', 2),
  (623, 1479, 'Bestlink College of the Philippines', '2024-2025', 'Summer', 'Year 2', '2.47', '9.00', 3, '0000-00-00 00:00:00', 2),
  (624, 1480, 'Bestlink College of the Philippines', '2025-2026', '2nd', 'Year 2', '2.20', '9.00', 3, '0000-00-00 00:00:00', 2),
  (625, 1481, 'Bestlink College of the Philippines', '2026-2027', '1st', 'Year 2', '3.30', '9.00', 3, '0000-00-00 00:00:00', 2),
  (626, 1482, 'Bestlink College of the Philippines', '2024-2025', 'Summer', 'Year 3', '4.40', '9.00', 3, '0000-00-00 00:00:00', 2),
  (627, 1483, 'Bestlink College of the Philippines', '2025-2026', '2nd', 'Year 3', '2.77', '9.00', 3, '0000-00-00 00:00:00', 2),
  (628, 1484, 'Bestlink College of the Philippines', '2026-2027', '1st', 'Year 3', '2.50', '9.00', 3, '0000-00-00 00:00:00', 2),
  (629, 1485, 'Bestlink College of the Philippines', '2024-2025', 'Summer', 'Year 4', '3.31', '7.00', 2, '0000-00-00 00:00:00', 2),
  (630, 1486, 'Bestlink College of the Philippines', '2025-2026', '2nd', 'Year 1', '3.49', '11.00', 4, '0000-00-00 00:00:00', 2),
  (631, 1487, 'Bestlink College of the Philippines', '2026-2027', '1st', 'Year 1', '1.61', '11.00', 4, '0000-00-00 00:00:00', 2),
  (632, 1488, 'Bestlink College of the Philippines', '2024-2025', 'Summer', 'Year 1', '2.71', '11.00', 4, '0000-00-00 00:00:00', 2),
  (633, 1489, 'Bestlink College of the Philippines', '2025-2026', '2nd', 'Year 1', '3.81', '11.00', 4, '0000-00-00 00:00:00', 2),
  (634, 1490, 'Bestlink College of the Philippines', '2026-2027', '1st', 'Year 1', '3.79', '11.00', 4, '0000-00-00 00:00:00', 2),
  (635, 1491, 'Bestlink College of the Philippines', '2024-2025', 'Summer', 'Year 1', '1.91', '11.00', 4, '0000-00-00 00:00:00', 2),
  (636, 1492, 'Bestlink College of the Philippines', '2025-2026', '2nd', 'Year 2', '3.10', '9.00', 3, '0000-00-00 00:00:00', 2),
  (637, 1493, 'Bestlink College of the Philippines', '2026-2027', '1st', 'Year 2', '4.20', '9.00', 3, '0000-00-00 00:00:00', 2),
  (638, 1494, 'Bestlink College of the Philippines', '2024-2025', 'Summer', 'Year 2', '2.57', '9.00', 3, '0000-00-00 00:00:00', 2),
  (639, 1495, 'Bestlink College of the Philippines', '2025-2026', '2nd', 'Year 2', '2.30', '9.00', 3, '0000-00-00 00:00:00', 2),
  (640, 1496, 'Bestlink College of the Philippines', '2026-2027', '1st', 'Year 3', '3.40', '9.00', 3, '0000-00-00 00:00:00', 2),
  (641, 1497, 'Bestlink College of the Philippines', '2024-2025', 'Summer', 'Year 3', '4.50', '9.00', 3, '0000-00-00 00:00:00', 2),
  (642, 1498, 'Bestlink College of the Philippines', '2025-2026', '2nd', 'Year 3', '1.50', '9.00', 3, '0000-00-00 00:00:00', 2),
  (643, 1499, 'Bestlink College of the Philippines', '2026-2027', '1st', 'Year 4', '2.31', '7.00', 2, '0000-00-00 00:00:00', 2),
  (644, 1500, 'Bestlink College of the Philippines', '2024-2025', 'Summer', 'Year 1', '3.61', '11.00', 4, '0000-00-00 00:00:00', 2),
  (645, 1501, 'Bestlink College of the Philippines', '2025-2026', '2nd', 'Year 1', '3.59', '11.00', 4, '0000-00-00 00:00:00', 2),
  (646, 1502, 'Bestlink College of the Philippines', '2026-2027', '1st', 'Year 1', '1.71', '11.00', 4, '0000-00-00 00:00:00', 2),
  (647, 1503, 'Bestlink College of the Philippines', '2024-2025', 'Summer', 'Year 1', '2.81', '11.00', 4, '0000-00-00 00:00:00', 2),
  (648, 1504, 'Bestlink College of the Philippines', '2025-2026', '2nd', 'Year 1', '3.91', '11.00', 4, '0000-00-00 00:00:00', 2),
  (649, 1505, 'Bestlink College of the Philippines', '2026-2027', '1st', 'Year 1', '2.77', '11.00', 4, '0000-00-00 00:00:00', 2),
  (650, 1506, 'Bestlink College of the Philippines', '2024-2025', 'Summer', 'Year 2', '2.10', '9.00', 3, '0000-00-00 00:00:00', 2);

-- ── Subject grades ───────────────────────────────────────────────────
--  final_rating is on the school's 1.00-5.00 scale, 1.00 best.
INSERT INTO `academic_grades` (
  `id`, `academic_history_id`, `subject_code`, `subject`, `subject_type`, `units`, `grade`, `final_rating`, `grade_status`, `source_system`, `semester_taken`, `received_at`, `instructor`, `term_status`, `instructor_confirmed`
) VALUES
  (2890, 601, 'GE-001', 'Purposive Communication', 'General Education', '3.00', 'P', '1', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2891, 601, 'MATH-101', 'Mathematics in the Contemporary World', 'Professional', '3.00', 'P', '1.5', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2892, 601, 'IT-101', 'Introduction to Computing', 'Professional', '3.00', 'P', '2', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2893, 601, 'PE-101', 'Physical Education', 'General Education', '2.00', 'P', '1', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-04', 'final', 1),
  (2894, 602, 'GE-001', 'Purposive Communication', 'General Education', '3.00', 'P', '2.1', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2895, 602, 'MATH-101', 'Mathematics in the Contemporary World', 'Professional', '3.00', 'P', '2.6', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2896, 602, 'IT-101', 'Introduction to Computing', 'Professional', '3.00', 'F', '3.1', 'failed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2897, 602, 'PE-101', 'Physical Education', 'General Education', '2.00', 'P', '2.1', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-04', 'final', 1),
  (2898, 603, 'GE-001', 'Purposive Communication', 'General Education', '3.00', 'F', '3.2', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2899, 603, 'MATH-101', 'Mathematics in the Contemporary World', 'Professional', '3.00', 'F', '3.7', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2900, 603, 'IT-101', 'Introduction to Computing', 'Professional', '3.00', 'F', '4.2', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2901, 603, 'PE-101', 'Physical Education', 'General Education', '2.00', 'F', '3.2', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-04', 'final', 1),
  (2902, 604, 'GE-001', 'Purposive Communication', 'General Education', '3.00', 'F', '4.3', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2903, 604, 'MATH-101', 'Mathematics in the Contemporary World', 'Professional', '3.00', 'F', '4.8', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2904, 604, 'IT-101', 'Introduction to Computing', 'Professional', '3.00', 'P', '1.2', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2905, 604, 'PE-101', 'Physical Education', 'General Education', '2.00', 'F', '4.3', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-04', 'final', 1),
  (2906, 605, 'GE-001', 'Purposive Communication', 'General Education', '3.00', 'P', '1.3', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2907, 605, 'MATH-101', 'Mathematics in the Contemporary World', 'Professional', '3.00', 'P', '1.8', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2908, 605, 'IT-101', 'Introduction to Computing', 'Professional', '3.00', 'P', '2.3', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2909, 605, 'PE-101', 'Physical Education', 'General Education', '2.00', 'P', '1.3', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-04', 'final', 1),
  (2910, 606, 'GE-001', 'Purposive Communication', 'General Education', '3.00', 'P', '2.4', 'passed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2911, 606, 'MATH-101', 'Mathematics in the Contemporary World', 'Professional', '3.00', 'P', '2.9', 'passed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2912, 606, 'IT-101', 'Introduction to Computing', 'Professional', '3.00', 'F', '3.4', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2913, 606, 'PE-101', 'Physical Education', 'General Education', '2.00', 'P', '2.4', 'passed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-04', 'final', 1),
  (2914, 607, 'IT-201', 'Data Structures', 'Professional', '3.00', 'F', '3.5', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2915, 607, 'MATH-102', 'Calculus 1', 'Professional', '3.00', 'F', '4', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2916, 607, 'ENT-101', 'Entrepreneurship', 'Professional', '3.00', 'F', '4.5', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2917, 608, 'IT-201', 'Data Structures', 'Professional', '3.00', 'F', '4.6', 'failed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2918, 608, 'MATH-102', 'Calculus 1', 'Professional', '3.00', 'P', '1', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2919, 608, 'ENT-101', 'Entrepreneurship', 'Professional', '3.00', 'P', '1.5', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2920, 609, 'IT-201', 'Data Structures', 'Professional', '3.00', 'P', '1.6', 'passed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2921, 609, 'MATH-102', 'Calculus 1', 'Professional', '3.00', 'P', '2.1', 'passed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2922, 609, 'ENT-101', 'Entrepreneurship', 'Professional', '3.00', 'P', '2.6', 'passed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2923, 610, 'IT-201', 'Data Structures', 'Professional', '3.00', 'P', '2.7', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2924, 610, 'MATH-102', 'Calculus 1', 'Professional', '3.00', 'F', '3.2', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2925, 610, 'ENT-101', 'Entrepreneurship', 'Professional', '3.00', 'F', '3.7', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2926, 611, 'IT-301', 'Information Management', 'Professional', '3.00', 'F', '3.8', 'failed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2927, 611, 'ACC-301', 'Accounting 1', 'Professional', '3.00', 'F', '4.3', 'failed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2928, 611, 'RES-101', 'Research Methods', 'Professional', '3.00', 'F', '4.8', 'failed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2929, 612, 'IT-301', 'Information Management', 'Professional', '3.00', 'F', '4.9', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2930, 612, 'ACC-301', 'Accounting 1', 'Professional', '3.00', 'P', '1.3', 'passed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2931, 612, 'RES-101', 'Research Methods', 'Professional', '3.00', 'P', '1.8', 'passed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2932, 613, 'IT-301', 'Information Management', 'Professional', '3.00', 'P', '1.9', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2933, 613, 'ACC-301', 'Accounting 1', 'Professional', '3.00', 'P', '2.4', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2934, 613, 'RES-101', 'Research Methods', 'Professional', '3.00', 'P', '2.9', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2935, 614, 'IT-401', 'Capstone Project 1', 'Professional', '4.00', 'P', '3', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2936, 614, 'MGT-402', 'Strategic Management', 'Professional', '3.00', 'F', '3.5', 'failed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2937, 615, 'IT-401', 'Capstone Project 1', 'Professional', '4.00', 'F', '4.1', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2938, 615, 'MGT-402', 'Strategic Management', 'Professional', '3.00', 'F', '4.6', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2939, 616, 'GE-001', 'Purposive Communication', 'General Education', '3.00', 'P', '1.1', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2940, 616, 'MATH-101', 'Mathematics in the Contemporary World', 'Professional', '3.00', 'P', '1.6', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2941, 616, 'IT-101', 'Introduction to Computing', 'Professional', '3.00', 'P', '2.1', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2942, 616, 'PE-101', 'Physical Education', 'General Education', '2.00', 'P', '1.1', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-04', 'final', 1),
  (2943, 617, 'GE-001', 'Purposive Communication', 'General Education', '3.00', 'P', '2.2', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2944, 617, 'MATH-101', 'Mathematics in the Contemporary World', 'Professional', '3.00', 'P', '2.7', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2945, 617, 'IT-101', 'Introduction to Computing', 'Professional', '3.00', 'F', '3.2', 'failed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2946, 617, 'PE-101', 'Physical Education', 'General Education', '2.00', 'P', '2.2', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-04', 'final', 1),
  (2947, 618, 'GE-001', 'Purposive Communication', 'General Education', '3.00', 'F', '3.3', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2948, 618, 'MATH-101', 'Mathematics in the Contemporary World', 'Professional', '3.00', 'F', '3.8', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2949, 618, 'IT-101', 'Introduction to Computing', 'Professional', '3.00', 'F', '4.3', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2950, 618, 'PE-101', 'Physical Education', 'General Education', '2.00', 'F', '3.3', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-04', 'final', 1),
  (2951, 619, 'GE-001', 'Purposive Communication', 'General Education', '3.00', 'F', '4.4', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2952, 619, 'MATH-101', 'Mathematics in the Contemporary World', 'Professional', '3.00', 'F', '4.9', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2953, 619, 'IT-101', 'Introduction to Computing', 'Professional', '3.00', 'P', '1.3', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2954, 619, 'PE-101', 'Physical Education', 'General Education', '2.00', 'F', '4.4', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-04', 'final', 1),
  (2955, 620, 'GE-001', 'Purposive Communication', 'General Education', '3.00', 'P', '1.4', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2956, 620, 'MATH-101', 'Mathematics in the Contemporary World', 'Professional', '3.00', 'P', '1.9', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2957, 620, 'IT-101', 'Introduction to Computing', 'Professional', '3.00', 'P', '2.4', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2958, 620, 'PE-101', 'Physical Education', 'General Education', '2.00', 'P', '1.4', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-04', 'final', 1),
  (2959, 621, 'IT-201', 'Data Structures', 'Professional', '3.00', 'P', '2.5', 'passed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2960, 621, 'MATH-102', 'Calculus 1', 'Professional', '3.00', 'P', '3', 'passed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2961, 621, 'ENT-101', 'Entrepreneurship', 'Professional', '3.00', 'F', '3.5', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2962, 622, 'IT-201', 'Data Structures', 'Professional', '3.00', 'F', '3.6', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2963, 622, 'MATH-102', 'Calculus 1', 'Professional', '3.00', 'F', '4.1', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2964, 622, 'ENT-101', 'Entrepreneurship', 'Professional', '3.00', 'F', '4.6', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2965, 623, 'IT-201', 'Data Structures', 'Professional', '3.00', 'F', '4.7', 'failed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2966, 623, 'MATH-102', 'Calculus 1', 'Professional', '3.00', 'P', '1.1', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2967, 623, 'ENT-101', 'Entrepreneurship', 'Professional', '3.00', 'P', '1.6', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2968, 624, 'IT-201', 'Data Structures', 'Professional', '3.00', 'P', '1.7', 'passed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2969, 624, 'MATH-102', 'Calculus 1', 'Professional', '3.00', 'P', '2.2', 'passed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2970, 624, 'ENT-101', 'Entrepreneurship', 'Professional', '3.00', 'P', '2.7', 'passed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2971, 625, 'IT-201', 'Data Structures', 'Professional', '3.00', 'P', '2.8', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2972, 625, 'MATH-102', 'Calculus 1', 'Professional', '3.00', 'F', '3.3', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2973, 625, 'ENT-101', 'Entrepreneurship', 'Professional', '3.00', 'F', '3.8', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2974, 626, 'IT-301', 'Information Management', 'Professional', '3.00', 'F', '3.9', 'failed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2975, 626, 'ACC-301', 'Accounting 1', 'Professional', '3.00', 'F', '4.4', 'failed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2976, 626, 'RES-101', 'Research Methods', 'Professional', '3.00', 'F', '4.9', 'failed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2977, 627, 'IT-301', 'Information Management', 'Professional', '3.00', 'F', '5', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2978, 627, 'ACC-301', 'Accounting 1', 'Professional', '3.00', 'P', '1.4', 'passed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2979, 627, 'RES-101', 'Research Methods', 'Professional', '3.00', 'P', '1.9', 'passed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2980, 628, 'IT-301', 'Information Management', 'Professional', '3.00', 'P', '2', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2981, 628, 'ACC-301', 'Accounting 1', 'Professional', '3.00', 'P', '2.5', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2982, 628, 'RES-101', 'Research Methods', 'Professional', '3.00', 'P', '3', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2983, 629, 'IT-401', 'Capstone Project 1', 'Professional', '4.00', 'F', '3.1', 'failed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2984, 629, 'MGT-402', 'Strategic Management', 'Professional', '3.00', 'F', '3.6', 'failed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2985, 630, 'GE-001', 'Purposive Communication', 'General Education', '3.00', 'F', '4.2', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2986, 630, 'MATH-101', 'Mathematics in the Contemporary World', 'Professional', '3.00', 'F', '4.7', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2987, 630, 'IT-101', 'Introduction to Computing', 'Professional', '3.00', 'P', '1.1', 'passed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2988, 630, 'PE-101', 'Physical Education', 'General Education', '2.00', 'F', '4.2', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-04', 'final', 1),
  (2989, 631, 'GE-001', 'Purposive Communication', 'General Education', '3.00', 'P', '1.2', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1);

INSERT INTO `academic_grades` (
  `id`, `academic_history_id`, `subject_code`, `subject`, `subject_type`, `units`, `grade`, `final_rating`, `grade_status`, `source_system`, `semester_taken`, `received_at`, `instructor`, `term_status`, `instructor_confirmed`
) VALUES
  (2990, 631, 'MATH-101', 'Mathematics in the Contemporary World', 'Professional', '3.00', 'P', '1.7', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2991, 631, 'IT-101', 'Introduction to Computing', 'Professional', '3.00', 'P', '2.2', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2992, 631, 'PE-101', 'Physical Education', 'General Education', '2.00', 'P', '1.2', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-04', 'final', 1),
  (2993, 632, 'GE-001', 'Purposive Communication', 'General Education', '3.00', 'P', '2.3', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2994, 632, 'MATH-101', 'Mathematics in the Contemporary World', 'Professional', '3.00', 'P', '2.8', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2995, 632, 'IT-101', 'Introduction to Computing', 'Professional', '3.00', 'F', '3.3', 'failed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (2996, 632, 'PE-101', 'Physical Education', 'General Education', '2.00', 'P', '2.3', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-04', 'final', 1),
  (2997, 633, 'GE-001', 'Purposive Communication', 'General Education', '3.00', 'F', '3.4', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (2998, 633, 'MATH-101', 'Mathematics in the Contemporary World', 'Professional', '3.00', 'F', '3.9', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (2999, 633, 'IT-101', 'Introduction to Computing', 'Professional', '3.00', 'F', '4.4', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (3000, 633, 'PE-101', 'Physical Education', 'General Education', '2.00', 'F', '3.4', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-04', 'final', 1),
  (3001, 634, 'GE-001', 'Purposive Communication', 'General Education', '3.00', 'F', '4.5', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (3002, 634, 'MATH-101', 'Mathematics in the Contemporary World', 'Professional', '3.00', 'F', '5', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (3003, 634, 'IT-101', 'Introduction to Computing', 'Professional', '3.00', 'P', '1.4', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (3004, 634, 'PE-101', 'Physical Education', 'General Education', '2.00', 'F', '4.5', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-04', 'final', 1),
  (3005, 635, 'GE-001', 'Purposive Communication', 'General Education', '3.00', 'P', '1.5', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (3006, 635, 'MATH-101', 'Mathematics in the Contemporary World', 'Professional', '3.00', 'P', '2', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (3007, 635, 'IT-101', 'Introduction to Computing', 'Professional', '3.00', 'P', '2.5', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (3008, 635, 'PE-101', 'Physical Education', 'General Education', '2.00', 'P', '1.5', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-04', 'final', 1),
  (3009, 636, 'IT-201', 'Data Structures', 'Professional', '3.00', 'P', '2.6', 'passed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (3010, 636, 'MATH-102', 'Calculus 1', 'Professional', '3.00', 'F', '3.1', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (3011, 636, 'ENT-101', 'Entrepreneurship', 'Professional', '3.00', 'F', '3.6', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (3012, 637, 'IT-201', 'Data Structures', 'Professional', '3.00', 'F', '3.7', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (3013, 637, 'MATH-102', 'Calculus 1', 'Professional', '3.00', 'F', '4.2', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (3014, 637, 'ENT-101', 'Entrepreneurship', 'Professional', '3.00', 'F', '4.7', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (3015, 638, 'IT-201', 'Data Structures', 'Professional', '3.00', 'F', '4.8', 'failed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (3016, 638, 'MATH-102', 'Calculus 1', 'Professional', '3.00', 'P', '1.2', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (3017, 638, 'ENT-101', 'Entrepreneurship', 'Professional', '3.00', 'P', '1.7', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (3018, 639, 'IT-201', 'Data Structures', 'Professional', '3.00', 'P', '1.8', 'passed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (3019, 639, 'MATH-102', 'Calculus 1', 'Professional', '3.00', 'P', '2.3', 'passed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (3020, 639, 'ENT-101', 'Entrepreneurship', 'Professional', '3.00', 'P', '2.8', 'passed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (3021, 640, 'IT-301', 'Information Management', 'Professional', '3.00', 'P', '2.9', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (3022, 640, 'ACC-301', 'Accounting 1', 'Professional', '3.00', 'F', '3.4', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (3023, 640, 'RES-101', 'Research Methods', 'Professional', '3.00', 'F', '3.9', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (3024, 641, 'IT-301', 'Information Management', 'Professional', '3.00', 'F', '4', 'failed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (3025, 641, 'ACC-301', 'Accounting 1', 'Professional', '3.00', 'F', '4.5', 'failed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (3026, 641, 'RES-101', 'Research Methods', 'Professional', '3.00', 'F', '5', 'failed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (3027, 642, 'IT-301', 'Information Management', 'Professional', '3.00', 'P', '1', 'passed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (3028, 642, 'ACC-301', 'Accounting 1', 'Professional', '3.00', 'P', '1.5', 'passed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (3029, 642, 'RES-101', 'Research Methods', 'Professional', '3.00', 'P', '2', 'passed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (3030, 643, 'IT-401', 'Capstone Project 1', 'Professional', '4.00', 'P', '2.1', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (3031, 643, 'MGT-402', 'Strategic Management', 'Professional', '3.00', 'P', '2.6', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (3032, 644, 'GE-001', 'Purposive Communication', 'General Education', '3.00', 'F', '3.2', 'failed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (3033, 644, 'MATH-101', 'Mathematics in the Contemporary World', 'Professional', '3.00', 'F', '3.7', 'failed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (3034, 644, 'IT-101', 'Introduction to Computing', 'Professional', '3.00', 'F', '4.2', 'failed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (3035, 644, 'PE-101', 'Physical Education', 'General Education', '2.00', 'F', '3.2', 'failed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-04', 'final', 1),
  (3036, 645, 'GE-001', 'Purposive Communication', 'General Education', '3.00', 'F', '4.3', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (3037, 645, 'MATH-101', 'Mathematics in the Contemporary World', 'Professional', '3.00', 'F', '4.8', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (3038, 645, 'IT-101', 'Introduction to Computing', 'Professional', '3.00', 'P', '1.2', 'passed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (3039, 645, 'PE-101', 'Physical Education', 'General Education', '2.00', 'F', '4.3', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-04', 'final', 1),
  (3040, 646, 'GE-001', 'Purposive Communication', 'General Education', '3.00', 'P', '1.3', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (3041, 646, 'MATH-101', 'Mathematics in the Contemporary World', 'Professional', '3.00', 'P', '1.8', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (3042, 646, 'IT-101', 'Introduction to Computing', 'Professional', '3.00', 'P', '2.3', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (3043, 646, 'PE-101', 'Physical Education', 'General Education', '2.00', 'P', '1.3', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-04', 'final', 1),
  (3044, 647, 'GE-001', 'Purposive Communication', 'General Education', '3.00', 'P', '2.4', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (3045, 647, 'MATH-101', 'Mathematics in the Contemporary World', 'Professional', '3.00', 'P', '2.9', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (3046, 647, 'IT-101', 'Introduction to Computing', 'Professional', '3.00', 'F', '3.4', 'failed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (3047, 647, 'PE-101', 'Physical Education', 'General Education', '2.00', 'P', '2.4', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-04', 'final', 1),
  (3048, 648, 'GE-001', 'Purposive Communication', 'General Education', '3.00', 'F', '3.5', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (3049, 648, 'MATH-101', 'Mathematics in the Contemporary World', 'Professional', '3.00', 'F', '4', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (3050, 648, 'IT-101', 'Introduction to Computing', 'Professional', '3.00', 'F', '4.5', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (3051, 648, 'PE-101', 'Physical Education', 'General Education', '2.00', 'F', '3.5', 'failed', 'faculty', '2nd', '2026-09-27 10:06:05', 'FACULTY-04', 'final', 1),
  (3052, 649, 'GE-001', 'Purposive Communication', 'General Education', '3.00', 'F', '4.6', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (3053, 649, 'MATH-101', 'Mathematics in the Contemporary World', 'Professional', '3.00', 'P', '1', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (3054, 649, 'IT-101', 'Introduction to Computing', 'Professional', '3.00', 'P', '1.5', 'passed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1),
  (3055, 649, 'PE-101', 'Physical Education', 'General Education', '2.00', 'F', '4.6', 'failed', 'faculty', '1st', '2026-09-27 10:06:05', 'FACULTY-04', 'final', 1),
  (3056, 650, 'IT-201', 'Data Structures', 'Professional', '3.00', 'P', '1.6', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-01', 'final', 1),
  (3057, 650, 'MATH-102', 'Calculus 1', 'Professional', '3.00', 'P', '2.1', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-02', 'final', 1),
  (3058, 650, 'ENT-101', 'Entrepreneurship', 'Professional', '3.00', 'P', '2.6', 'passed', 'faculty', 'Summer', '2026-09-27 10:06:05', 'FACULTY-03', 'final', 1);

-- ── Applicants, waiting behind the Receive Student button ────────────
--  These are APPLICANTS, not students: no users row, no grades, no
--  documents. Accepting one through the UI is what creates the student,
--  and that is the flow they exist to be accepted BY.
--
--  The duplicates deliberately carry an EXISTING student_number, so the
--  modal's Duplication Check has something real to match rather than
--  always reporting "no match found". The rest carry NULL, because the
--  Enrollment System issues the number on Accept.
INSERT INTO `enrollments` (
  `id`, `first_name`, `middle_name`, `last_name`, `gender`, `civil_status`, `nationality`, `religion`, `place_of_birth`, `birth_date`, `student_number`, `father_name`, `mother_name`, `email`, `address`, `contact_number`, `prev_school_name`, `prev_school_last_year`, `prev_school_graduated_sy`, `emergency_name`, `emergency_relationship`, `emergency_contact`, `course`, `year_level`, `school_year`, `semester`, `status`, `received_at`
) VALUES
  (30, 'Noel', 'S', 'Diaz', 'Male', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2005-01-01', NULL, 'Diaz Sr.', 'Hernandez', 'noel.diaz.0@seed.receive.test', 'SEEDDATA- Diaz, Quezon City', '09200000000', 'Quezon City Science High School', 'Grade 12', '2025-2026', 'Magsaysay, Diaz', 'Parent', '09300000000', 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)', 1, '2026-2027', '1st', 'pending', NULL),
  (31, 'Marilen', 'R', 'Ignacio', 'Female', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2006-02-02', NULL, 'Ignacio Sr.', 'Ignacio', 'marilen.ignacio.1@seed.receive.test', 'SEEDDATA- Ignacio, Quezon City', '09200007919', 'Quezon City Science High School', 'Grade 12', '2025-2026', 'Nolasco, Ignacio', 'Parent', '09300006151', 'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION (BSBA)', 2, '2025-2026', '2nd', 'pending', NULL),
  (32, 'Rogelio', 'C', 'Pangilinan', 'Male', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2007-03-03', NULL, 'Pangilinan Sr.', 'Javier', 'rogelio.pangilinan.2@seed.receive.test', 'SEEDDATA- Pangilinan, Quezon City', '09200015838', 'Quezon City Science High School', 'Grade 12', '2025-2026', 'Pangilinan, Pangilinan', 'Parent', '09300012302', 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT (BSHM)', 3, '2024-2025', 'Summer', 'pending', NULL),
  (33, 'Kaye', 'M', 'Uy', 'Female', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2005-04-04', NULL, 'Uy Sr.', 'Lazaro', 'kaye.uy.3@seed.receive.test', 'SEEDDATA- Uy, Quezon City', '09200023757', 'Quezon City Science High School', 'Grade 12', '2025-2026', 'Quiambao, Uy', 'Parent', '09300018453', 'BACHELOR OF SCIENCE IN COMPUTER ENGINEERING (BSCpE)', 4, '2026-2027', '1st', 'pending', NULL),
  (34, 'Baldwin', 'A', 'Basco', 'Male', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2005-05-05', NULL, 'Basco Sr.', 'Magsaysay', 'baldwin.basco.4@seed.receive.test', 'SEEDDATA- Basco, Quezon City', '09200031676', 'Quezon City Science High School', 'Grade 12', '2025-2026', 'Roxas II, Basco', 'Parent', '09300024604', 'BACHELOR OF SCIENCE IN TOURISM MANAGEMENT (BSTM)', 1, '2025-2026', '2nd', 'pending', NULL),
  (35, 'Maegan', 'P', 'Gatmaitan', 'Female', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2006-06-06', NULL, 'Gatmaitan Sr.', 'Nolasco', 'maegan.gatmaitan.5@seed.receive.test', 'SEEDDATA- Gatmaitan, Quezon City', '09200039595', 'Quezon City Science High School', 'Grade 12', '2025-2026', 'Sison, Gatmaitan', 'Parent', '09300030755', 'BACHELOR OF SCIENCE IN OFFICE ADMINISTRATION (BSOA)', 2, '2024-2025', 'Summer', 'pending', NULL),
  (36, 'Dante', '', 'Magsaysay', 'Male', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2007-07-07', NULL, 'Magsaysay Sr.', 'Pangilinan', 'dante.magsaysay.6@seed.receive.test', 'SEEDDATA- Magsaysay, Quezon City', '09200047514', 'Quezon City Science High School', 'Grade 12', '2025-2026', 'Tolentino, Magsaysay', 'Parent', '09300036906', 'BACHELOR OF SCIENCE IN ACCOUNTING INFORMATION SYSTEM (BSAIS)', 3, '2026-2027', '1st', 'pending', NULL),
  (37, 'Irish', '', 'Sison', 'Female', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2005-08-08', NULL, 'Sison Sr.', 'Quiambao', 'irish.sison.7@seed.receive.test', 'SEEDDATA- Sison, Quezon City', '09200055433', 'Quezon City Science High School', 'Grade 12', '2025-2026', 'Uy, Sison', 'Parent', '09300043057', 'BACHELOR OF SCIENCE IN PSYCHOLOGY (BSP)', 4, '2025-2026', '2nd', 'pending', NULL),
  (38, 'Noel', 'S', 'Zamora', 'Male', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2005-09-09', NULL, 'Zamora Sr.', 'Roxas II', 'noel.zamora.8@seed.receive.test', 'SEEDDATA- Zamora, Quezon City', '09200063352', 'Quezon City Science High School', 'Grade 12', '2025-2026', 'Valencia, Zamora', 'Parent', '09300049208', 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)', 1, '2024-2025', 'Summer', 'pending', NULL),
  (39, 'Marilen', 'R', 'Evangelista', 'Female', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2006-10-10', NULL, 'Evangelista Sr.', 'Sison', 'marilen.evangelista.9@seed.receive.test', 'SEEDDATA- Evangelista, Quezon City', '09200071271', 'Quezon City Science High School', 'Grade 12', '2025-2026', 'Yulo, Evangelista', 'Parent', '09300055359', 'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION (BSBA)', 2, '2026-2027', '1st', 'pending', NULL),
  (40, 'Rogelio', 'C', 'Javier', 'Male', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2007-11-11', NULL, 'Javier Sr.', 'Tolentino', 'rogelio.javier.10@seed.receive.test', 'SEEDDATA- Javier, Quezon City', '09200079190', 'Quezon City Science High School', 'Grade 12', '2025-2026', 'Zamora, Javier', 'Parent', '09300061510', 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT (BSHM)', 3, '2025-2026', '2nd', 'received', '2026-09-24 10:06:05'),
  (41, 'Kaye', 'M', 'Quiambao', 'Female', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2005-12-12', NULL, 'Quiambao Sr.', 'Uy', 'kaye.quiambao.11@seed.receive.test', 'SEEDDATA- Quiambao, Quezon City', '09200087109', 'Quezon City Science High School', 'Grade 12', '2025-2026', 'Abad, Quiambao', 'Parent', '09300067661', 'BACHELOR OF SCIENCE IN COMPUTER ENGINEERING (BSCpE)', 4, '2024-2025', 'Summer', 'received', '2026-09-23 10:06:05'),
  (42, 'Baldwin', 'A', 'Valencia', 'Male', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2005-01-13', 'S2600037', 'Valencia Sr.', 'Valencia', 'baldwin.valencia.12@seed.receive.test', 'SEEDDATA- Valencia, Quezon City', '09200095028', NULL, NULL, NULL, 'Basco, Valencia', 'Parent', '09300073812', 'BACHELOR OF SCIENCE IN TOURISM MANAGEMENT (BSTM)', 1, '2026-2027', '1st', 'duplicate', '2026-09-22 10:06:05'),
  (43, 'Maegan', 'P', 'Cordero', 'Female', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2006-02-14', 'S2600040', 'Cordero Sr.', 'Yulo', 'maegan.cordero.13@seed.receive.test', 'SEEDDATA- Cordero, Quezon City', '09200102947', NULL, NULL, NULL, 'Cordero, Cordero', 'Parent', '09300079963', 'BACHELOR OF SCIENCE IN OFFICE ADMINISTRATION (BSOA)', 2, '2025-2026', '2nd', 'duplicate', '2026-09-21 10:06:05'),
  (44, 'Dante', '', 'Hernandez', 'Male', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2007-03-15', NULL, 'Hernandez Sr.', 'Zamora', 'dante.hernandez.14@seed.receive.test', 'SEEDDATA- Hernandez, Quezon City', '09200110866', 'Quezon City Science High School', 'Grade 12', '2025-2026', 'Diaz, Hernandez', 'Parent', '09300086114', 'BACHELOR OF SCIENCE IN ACCOUNTING INFORMATION SYSTEM (BSAIS)', 3, '2024-2025', 'Summer', 'pending', NULL),
  (45, 'Irish', '', 'Nolasco', 'Female', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2005-04-16', NULL, 'Nolasco Sr.', 'Abad', 'irish.nolasco.15@seed.receive.test', 'SEEDDATA- Nolasco, Quezon City', '09200118785', 'Quezon City Science High School', 'Grade 12', '2025-2026', 'Evangelista, Nolasco', 'Parent', '09300092265', 'BACHELOR OF SCIENCE IN PSYCHOLOGY (BSP)', 4, '2026-2027', '1st', 'pending', NULL),
  (46, 'Noel', 'S', 'Tolentino', 'Male', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2005-05-17', NULL, 'Tolentino Sr.', 'Basco', 'noel.tolentino.16@seed.receive.test', 'SEEDDATA- Tolentino, Quezon City', '09200126704', 'Quezon City Science High School', 'Grade 12', '2025-2026', 'Fajardo, Tolentino', 'Parent', '09300098416', 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)', 1, '2025-2026', '2nd', 'pending', NULL),
  (47, 'Marilen', 'R', 'Abad', 'Female', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2006-06-18', NULL, 'Abad Sr.', 'Cordero', 'marilen.abad.17@seed.receive.test', 'SEEDDATA- Abad, Quezon City', '09200134623', 'Quezon City Science High School', 'Grade 12', '2025-2026', 'Gatmaitan, Abad', 'Parent', '09300104567', 'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION (BSBA)', 2, '2024-2025', 'Summer', 'pending', NULL),
  (48, 'Rogelio', 'C', 'Fajardo', 'Male', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2007-07-19', NULL, 'Fajardo Sr.', 'Diaz', 'rogelio.fajardo.18@seed.receive.test', 'SEEDDATA- Fajardo, Quezon City', '09200142542', 'Quezon City Science High School', 'Grade 12', '2025-2026', 'Hernandez, Fajardo', 'Parent', '09300110718', 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT (BSHM)', 3, '2026-2027', '1st', 'pending', NULL),
  (49, 'Kaye', 'M', 'Lazaro', 'Female', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2005-08-20', NULL, 'Lazaro Sr.', 'Evangelista', 'kaye.lazaro.19@seed.receive.test', 'SEEDDATA- Lazaro, Quezon City', '09200150461', 'Quezon City Science High School', 'Grade 12', '2025-2026', 'Ignacio, Lazaro', 'Parent', '09300116869', 'BACHELOR OF SCIENCE IN COMPUTER ENGINEERING (BSCpE)', 4, '2025-2026', '2nd', 'pending', NULL),
  (50, 'Baldwin', 'A', 'Roxas II', 'Male', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2005-09-21', NULL, 'Roxas II Sr.', 'Fajardo', 'baldwin.roxas ii.20@seed.receive.test', 'SEEDDATA- Roxas II, Quezon City', '09200158380', 'Quezon City Science High School', 'Grade 12', '2025-2026', 'Javier, Roxas II', 'Parent', '09300123020', 'BACHELOR OF SCIENCE IN TOURISM MANAGEMENT (BSTM)', 1, '2024-2025', 'Summer', 'pending', NULL),
  (51, 'Maegan', 'P', 'Yulo', 'Female', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2006-10-22', NULL, 'Yulo Sr.', 'Gatmaitan', 'maegan.yulo.21@seed.receive.test', 'SEEDDATA- Yulo, Quezon City', '09200166299', 'Quezon City Science High School', 'Grade 12', '2025-2026', 'Lazaro, Yulo', 'Parent', '09300129171', 'BACHELOR OF SCIENCE IN OFFICE ADMINISTRATION (BSOA)', 2, '2026-2027', '1st', 'pending', NULL),
  (52, 'Dante', '', 'Diaz', 'Male', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2007-11-23', NULL, 'Diaz Sr.', 'Hernandez', 'dante.diaz.22@seed.receive.test', 'SEEDDATA- Diaz, Quezon City', '09200174218', 'Quezon City Science High School', 'Grade 12', '2025-2026', 'Magsaysay, Diaz', 'Parent', '09300135322', 'BACHELOR OF SCIENCE IN ACCOUNTING INFORMATION SYSTEM (BSAIS)', 3, '2025-2026', '2nd', 'pending', NULL),
  (53, 'Irish', '', 'Ignacio', 'Female', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2005-12-24', NULL, 'Ignacio Sr.', 'Ignacio', 'irish.ignacio.23@seed.receive.test', 'SEEDDATA- Ignacio, Quezon City', '09200182137', 'Quezon City Science High School', 'Grade 12', '2025-2026', 'Nolasco, Ignacio', 'Parent', '09300141473', 'BACHELOR OF SCIENCE IN PSYCHOLOGY (BSP)', 4, '2024-2025', 'Summer', 'pending', NULL),
  (54, 'Noel', 'S', 'Pangilinan', 'Male', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2005-01-25', NULL, 'Pangilinan Sr.', 'Javier', 'noel.pangilinan.24@seed.receive.test', 'SEEDDATA- Pangilinan, Quezon City', '09200190056', 'Quezon City Science High School', 'Grade 12', '2025-2026', 'Pangilinan, Pangilinan', 'Parent', '09300147624', 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)', 1, '2026-2027', '1st', 'received', '2026-09-10 10:06:05'),
  (55, 'Marilen', 'R', 'Uy', 'Female', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2006-02-26', NULL, 'Uy Sr.', 'Lazaro', 'marilen.uy.25@seed.receive.test', 'SEEDDATA- Uy, Quezon City', '09200197975', 'Quezon City Science High School', 'Grade 12', '2025-2026', 'Quiambao, Uy', 'Parent', '09300153775', 'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION (BSBA)', 2, '2025-2026', '2nd', 'received', '2026-09-09 10:06:05'),
  (56, 'Rogelio', 'C', 'Basco', 'Male', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2007-03-27', 'S2600079', 'Basco Sr.', 'Magsaysay', 'rogelio.basco.26@seed.receive.test', 'SEEDDATA- Basco, Quezon City', '09200205894', NULL, NULL, NULL, 'Roxas II, Basco', 'Parent', '09300159926', 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT (BSHM)', 3, '2024-2025', 'Summer', 'duplicate', '2026-09-08 10:06:05'),
  (57, 'Kaye', 'M', 'Gatmaitan', 'Female', 'Single', 'Filipino', 'Roman Catholic', 'Quezon City', '2005-04-01', 'S2600082', 'Gatmaitan Sr.', 'Nolasco', 'kaye.gatmaitan.27@seed.receive.test', 'SEEDDATA- Gatmaitan, Quezon City', '09200213813', NULL, NULL, NULL, 'Sison, Gatmaitan', 'Parent', '09300166077', 'BACHELOR OF SCIENCE IN COMPUTER ENGINEERING (BSCpE)', 4, '2026-2027', '1st', 'duplicate', '2026-09-07 10:06:05');

SET FOREIGN_KEY_CHECKS = 1;

-- ── What landed ──────────────────────────────────────────────────────
SELECT 'students' AS what, COUNT(*) AS n FROM students WHERE student_number LIKE 'S27%'
UNION ALL SELECT 'portal accounts', COUNT(*) FROM users u JOIN students s ON s.id=u.student_id WHERE s.student_number LIKE 'S27%'
UNION ALL SELECT 'term records', COUNT(*) FROM academic_history h JOIN students s ON s.id=h.student_id WHERE s.student_number LIKE 'S27%'
UNION ALL SELECT 'subject grades', COUNT(*) FROM academic_grades g JOIN academic_history h ON h.id=g.academic_history_id JOIN students s ON s.id=h.student_id WHERE s.student_number LIKE 'S27%'
UNION ALL SELECT 'applicants', COUNT(*) FROM enrollments WHERE email LIKE '%@seed.receive.test';
