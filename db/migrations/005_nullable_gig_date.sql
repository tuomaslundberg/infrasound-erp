-- Allow gig_date to be NULL for inquiry-status gigs where the customer
-- has not yet provided a date. The owner fills this in before quoting.
ALTER TABLE gigs MODIFY gig_date DATE NULL;
