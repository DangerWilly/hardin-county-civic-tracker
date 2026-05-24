-- 0005_seed_bodies.sql — pre-create the bodies you'll most likely add meetings to.
--
-- This makes day-one admin use immediate: log in, click "New Meeting," there's
-- already a "Kenton City Council" option in the dropdown. Saves you from having
-- to create bodies before you can create your first meeting.
--
-- All fields are stubs — meeting_info, phone, etc. are placeholders you should
-- correct via the admin UI once you've verified the real numbers from the
-- official sites. The data has source='manual' so you can tell at a glance these
-- were seeded vs. scraped.

INSERT INTO bodies (jurisdiction_id, slug, name, kind, meeting_info, source)
VALUES (
    (SELECT id FROM jurisdictions WHERE slug='oh-hardin-kenton'),
    'city-council',
    'Kenton City Council',
    'city_council',
    'Regular meetings: 2nd & 4th Mondays at 7:00 PM, City Building (verify before relying)',
    'manual'
);

INSERT INTO bodies (jurisdiction_id, slug, name, kind, meeting_info, source)
VALUES (
    (SELECT id FROM jurisdictions WHERE slug='oh-hardin'),
    'commissioners',
    'Hardin County Commissioners',
    'county_commission',
    'Meetings typically held weekday mornings at the Courthouse (verify before relying)',
    'manual'
);

INSERT INTO bodies (jurisdiction_id, slug, name, kind, meeting_info, source)
VALUES (
    (SELECT id FROM jurisdictions WHERE slug='oh-hardin-kenton'),
    'school-board',
    'Kenton City Schools Board of Education',
    'school_board',
    'Typically meets monthly (verify schedule)',
    'manual'
);

-- Pleasant Township is where Kenton sits. We don't yet have a township row in
-- jurisdictions (we modeled city → county → state → federal). For now park
-- trustees under the county; we can refactor jurisdictions later if we add
-- many more townships.
INSERT INTO bodies (jurisdiction_id, slug, name, kind, meeting_info, source)
VALUES (
    (SELECT id FROM jurisdictions WHERE slug='oh-hardin'),
    'pleasant-township-trustees',
    'Pleasant Township Trustees',
    'township_trustees',
    'Meeting schedule varies (verify)',
    'manual'
);
