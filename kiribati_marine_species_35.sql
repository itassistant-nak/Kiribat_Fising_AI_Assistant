-- Kiribati marine reference: 35 source entries, verbatim taxonomy and names.
-- Review taxonomy before treating entries as verified species.
-- This is a standalone table; no existing project tables are modified.
CREATE TABLE IF NOT EXISTS `kiribati_marine_reference` (
  `id` INT UNSIGNED NOT NULL PRIMARY KEY,
  `i_kiribati_name` VARCHAR(150) NOT NULL,
  `common_name` VARCHAR(200) NOT NULL,
  `scientific_name_as_provided` VARCHAR(200) NOT NULL,
  `verification_status` VARCHAR(30) NOT NULL DEFAULT 'needs_review',
  `review_notes` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Re-importing updates these 35 IDs, leaving other rows untouched.
INSERT INTO `kiribati_marine_reference`
(`id`, `i_kiribati_name`, `common_name`, `scientific_name_as_provided`, `verification_status`, `review_notes`)
VALUES
(1, 'te amori', 'longtail silver biddy', 'Gerres longirostris', 'needs_review', ''),
(2, 'te ati', 'skipjack tuna', 'Katsuwonus pelamis', 'needs_review', ''),
(3, 'te aua', 'mullet', 'Multiple species (Muglidae)', 'needs_review', 'Family spelling in source may be Mugilidae; verify'),
(4, 'te baiku', 'stingrays', 'Multiple species', 'needs_review', ''),
(5, 'bai tabaa', 'squaretail mullet (juvenile)', 'Liza vaigiensis', 'needs_review', 'Check currently accepted taxonomy'),
(6, 'te baiura', 'yellowfin tuna', 'Thunnus albacares', 'needs_review', ''),
(7, 'te baneawa', 'milkfish', 'Chanos chanos', 'needs_review', ''),
(8, 'te bawe', 'blacktail snapper', 'Lutjanus fulvus', 'needs_review', ''),
(9, 'te bun', 'ark shell', 'Anadara uropigmelana', 'needs_review', 'Scientific spelling requires verification'),
(10, 'te bunii', 'pufferfish', 'Multiple species', 'needs_review', ''),
(11, 'te buuroo', 'cowrie shells', 'Multiple species (Cypraeidae)', 'needs_review', ''),
(12, 'te bwaitari', 'jellyfish', 'Multiple species', 'needs_review', ''),
(13, 'te bwaua', 'mullet', 'Multiple species (Muglidae)', 'needs_review', 'Family spelling in source may be Mugilidae; verify'),
(14, 'te bwaweina', 'blackspot snapper', 'Lutjanus fulviflamma', 'needs_review', ''),
(15, 'te bwere/katati', 'penshell', 'Pinnidae', 'needs_review', ''),
(16, 'te iaia', 'egg mass of seahares', '-', 'needs_review', ''),
(17, 'te ibo', 'peanut worm', 'Sipunculus indicus', 'needs_review', ''),
(18, 'te ikanibong', 'humpback red snapper', 'Lutjanus gibbus', 'needs_review', ''),
(19, 'te ika n aonora', 'reef or lagoonal fish', 'Multiple species', 'needs_review', ''),
(20, 'te ika n marawa', 'pelagic fish (e.g. tuna, billfish)', 'Multiple species (pelagics)', 'needs_review', ''),
(21, 'ikan te nama', 'lagoon fish', 'Multiple species', 'needs_review', ''),
(22, 'te ikan te rakai', 'reef fish', 'Multiple species', 'needs_review', ''),
(23, 'te ikari', 'bonefish', 'Albula glossondonta', 'needs_review', ''),
(24, 'te ika uraura', 'red grouper species', 'Plectropomus species', 'needs_review', ''),
(25, 'te ingo', 'two spot red snapper', 'Lutjanus bohar', 'needs_review', ''),
(26, 'te kamakama', 'crab', 'Multiple species', 'needs_review', ''),
(27, 'te karon', 'humphead wrasse', 'Cheilinus undulatus', 'needs_review', ''),
(28, 'te kataawa', 'striped surgeonfish', 'Acanthurus lineatus', 'needs_review', ''),
(29, 'te nikatona', 'clam', 'Quidnipagus palatam', 'needs_review', 'Scientific spelling requires verification'),
(30, 'te katura', 'clam', 'Mesodesma striata', 'needs_review', ''),
(31, 'te kiika', 'octopus', 'Octopus cyanea', 'needs_review', ''),
(32, 'te koikoi', 'clam', 'Asaphis violascens', 'needs_review', ''),
(33, 'te koinawa', 'convict surgeonfish', 'Acanthurus triostegus', 'needs_review', ''),
(34, 'te koumwara', 'Pacific asaphis', 'Gafrarium pectinatum', 'needs_review', ''),
(35, 'te kuau', 'groupers', 'Multiple species', 'needs_review', '')
ON DUPLICATE KEY UPDATE
  `i_kiribati_name`=VALUES(`i_kiribati_name`),
  `common_name`=VALUES(`common_name`),
  `scientific_name_as_provided`=VALUES(`scientific_name_as_provided`),
  `verification_status`=VALUES(`verification_status`),
  `review_notes`=VALUES(`review_notes`);
