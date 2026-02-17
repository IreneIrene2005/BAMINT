-- Find customers who haven't checked out AND have partial/unpaid balances
-- Update their bills status to 'partial'

-- QUERY 1: Identify customers who need status update
SELECT 
    b.id as bill_id,
    b.tenant_id,
    t.name,
    b.status as current_status,
    b.amount_due,
    b.amount_paid,
    (b.amount_due - b.amount_paid) as balance_remaining,
    rr.status as booking_status
FROM bills b
JOIN tenants t ON b.tenant_id = t.id
LEFT JOIN room_requests rr ON b.tenant_id = rr.tenant_id AND b.room_id = rr.room_id
WHERE b.status IN ('pending', 'unpaid', 'overdue')
    AND (rr.status != 'completed' OR rr.status IS NULL)
    AND b.amount_paid > 0
    AND b.amount_paid < b.amount_due
ORDER BY t.name;

-- QUERY 2: Update bills status to 'partial' for customers who:
-- - Haven't checked out yet (room_requests status is not 'completed')
-- - Have partial payments (amount_paid > 0 AND amount_paid < amount_due)
UPDATE bills b
LEFT JOIN room_requests rr ON b.tenant_id = rr.tenant_id AND b.room_id = rr.room_id
SET b.status = 'partial'
WHERE b.status IN ('pending', 'unpaid', 'overdue')
    AND (rr.status != 'completed' OR rr.status IS NULL)
    AND b.amount_paid > 0
    AND b.amount_paid < b.amount_due;

-- QUERY 3: Verify the update
SELECT 
    b.id,
    t.name,
    b.status,
    b.amount_due,
    b.amount_paid,
    rr.status as booking_status
FROM bills b
JOIN tenants t ON b.tenant_id = t.id
LEFT JOIN room_requests rr ON b.tenant_id = rr.tenant_id AND b.room_id = rr.room_id
WHERE b.status = 'partial'
ORDER BY t.name;
