-- Migration 013: fix valtteri.alanen transport_mode and default_car
--
-- Under the OLD role-based TravelCalculator, transport_mode='car_owner' for
-- sound engineering meant "Car 2 pickup by default; transport_override='car_owner'
-- on the gig row when he drives himself". The new person-based model uses
-- transport_mode='car_owner' exclusively for designated band car drivers
-- (Tuomas = Car 1, Mortti/Maxwell = Car 2). Everyone else who rides in a
-- band car is 'passenger'.
--
-- Valtteri rides in Car 2 by default. When he drives himself to a gig the
-- owner sets gig_personnel.transport_override = 'local'.

UPDATE users
SET    transport_mode = 'passenger',
       default_car    = 2
WHERE  username = 'valtteri.alanen';
