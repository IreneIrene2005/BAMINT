-- Broader query to see all active bills and their current status

SELECT 
    b.id,
    b.tenant_id,
    t.name,
    b.status,
    b.amount_due,
    b.amount_paid,
    (b.amount_due - b.amount_paid) as balance_remaining,
    rr.status as booking_status,
    t.status as tenant_status
FROM bills b
JOIN tenants t ON b.tenant_id = t.id
LEFT JOIN room_requests rr ON b.tenant_id = rr.tenant_id AND b.room_id = rr.room_id
WHERE b.status NOT IN ('paid', 'cancelled')
    AND NOT (b.status = 'paid' AND DATE_ADD(b.updated_at, INTERVAL 7 DAY) < NOW())
ORDER BY t.name;

-- Alternative: See customers by booking status
SELECT 
    t.id,
    t.name,
    t.status as tenant_status,
    rr.status as booking_status,
    COUNT(b.id) as bill_count,
    SUM(b.amount_due) as total_due,
    SUM(b.amount_paid) as total_paid
FROM tenants t
LEFT JOIN room_requests rr ON t.id = rr.tenant_id
LEFT JOIN bills b ON t.id = b.tenant_id
GROUP BY t.id
ORDER BY t.name;

-- Show active tenants with incomplete checkouts
SELECT 
    t.id,
    t.name,
    t.status,
    rr.status as booking_status,
    rr.checkin_date,
    rr.checkout_date
FROM tenants t
LEFT JOIN room_requests rr ON t.id = rr.tenant_id AND t.room_id = rr.room_id
WHERE t.status = 'active'
ORDER BY t.name;
