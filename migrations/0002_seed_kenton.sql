-- 0002_seed_kenton.sql — initial jurisdictions for v1 launch.
--
-- These INSERTs run exactly once (the migration runner records every applied
-- file in `_migrations`). To add data later, write a new numbered migration
-- or use the admin UI we'll build in step 6.

INSERT INTO jurisdictions (slug, name, kind, parent_id, centroid_lat, centroid_lng, metadata)
VALUES (
    'us-federal', 'United States', 'federal', NULL,
    NULL, NULL,
    '{"ocd_id":"ocd-division/country:us"}'
);

INSERT INTO jurisdictions (slug, name, kind, parent_id, centroid_lat, centroid_lng, metadata)
VALUES (
    'oh', 'Ohio', 'state',
    (SELECT id FROM jurisdictions WHERE slug = 'us-federal'),
    40.4173, -82.9071,
    '{"fips":"39","ocd_id":"ocd-division/country:us/state:oh"}'
);

INSERT INTO jurisdictions (slug, name, kind, parent_id, centroid_lat, centroid_lng, metadata)
VALUES (
    'oh-hardin', 'Hardin County', 'county',
    (SELECT id FROM jurisdictions WHERE slug = 'oh'),
    40.6614, -83.6614,
    '{"fips":"39065","ocd_id":"ocd-division/country:us/state:oh/county:hardin"}'
);

INSERT INTO jurisdictions (slug, name, kind, parent_id, centroid_lat, centroid_lng, metadata)
VALUES (
    'oh-hardin-kenton', 'Kenton', 'city',
    (SELECT id FROM jurisdictions WHERE slug = 'oh-hardin'),
    40.6470, -83.6097,
    '{"fips_place":"3940026"}'
);

-- A real piece of voter-info content for the county. You'll edit this to
-- reflect actual Hardin County BoE info — it's just a starting point.

INSERT INTO content_pages (jurisdiction_id, slug, title, body_md, source, published)
VALUES (
    (SELECT id FROM jurisdictions WHERE slug = 'oh-hardin'),
    'voter-info',
    'How to Vote in Hardin County',
    '## Register to vote

You can register online at [voteohio.gov](https://voteohio.gov) up to **30 days before** an election. Bring an Ohio driver''s license, state ID, or the last four digits of your Social Security number.

In person, register at:

- **Hardin County Board of Elections** — One Courthouse Square, Kenton
- The BMV when you renew your license
- Most public libraries

## Find your polling place

Look up your assigned polling place at the [Ohio voter lookup tool](https://lookup.boe.ohio.gov/vtrapp/hardin/vtrlookup.aspx).

## Early & absentee voting

- **Early in-person voting:** at the BoE office, dates set by the Secretary of State for each election
- **Absentee by mail:** request a ballot up to **7 days before** election day; it must be **postmarked the day before** the election

## What''s on your ballot

This page will soon include automatically updated ballot information for upcoming elections. *Building this is on the roadmap.*

## Contact your Board of Elections

- **Phone:** (419) 674-2230
- **Address:** One Courthouse Square, Kenton, OH 43326

*Spotted an error? Submit a correction below — corrections go to a human moderator.*',
    'manual',
    1
);
