-- Migration 011: add car route JSON columns to gigs
-- Stores the calculated route detail (waypoints + per-leg km) so it is
-- visible in the gig detail view and auditable after recalculation.

ALTER TABLE gigs
  ADD COLUMN car1_route_json TEXT DEFAULT NULL
    COMMENT 'JSON: {"waypoints":[{"label":"...","lat":...,"lng":...}],"one_way_km":...,"legs_km":[...]}',
  ADD COLUMN car2_route_json TEXT DEFAULT NULL
    COMMENT 'JSON: same shape as car1_route_json';
