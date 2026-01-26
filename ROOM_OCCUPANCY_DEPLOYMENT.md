# Room Occupancy System - Deployment Checklist

## Pre-Deployment Tasks

### 1. Database Migration ✓
- [ ] Backup current database
- [ ] Run migration: `db/migrate_room_occupancy.php`
  ```
  Expected Output:
  ✓ Added tenant_count column
  ✓ Added tenant info columns (name, email, phone, address)
  ✓ Added approved_date column
  Migration completed successfully!
  ```
- [ ] Verify new columns in `room_requests` table

### 2. Room Type Migration (Optional)
- [ ] Run migration: `db/migrate_room_types.php` (if using "Suite" previously)
  ```
  Expected Output:
  ✓ Updated X room(s) from 'Suite' to 'Bedspace'
  ✓ Standardized room type formatting
  Migration completed successfully!
  ```

### 3. Code Verification
- [ ] Verify `tenant_add_room.php` has validation logic
- [ ] Verify `room_requests_queue.php` has approval logic
- [ ] Check `rooms.php` dropdown for room types
- [ ] Verify `occupancy_reports.php` shows tenant totals

## Testing Phase

### Tenant Workflow Test
- [ ] Create test tenant account
- [ ] Navigate to "Add Room"
- [ ] Test with incomplete information (should fail)
- [ ] Test occupancy validation:
  - [ ] Request 2 people for Single room (should fail)
  - [ ] Request 3 people for Shared room (should fail)
  - [ ] Request 5 people for Bedspace room (should fail)
  - [ ] Request valid occupancy (should succeed)
- [ ] Verify request appears in "My Requests"

### Admin Workflow Test
- [ ] Login as admin
- [ ] Navigate to "Room Requests Queue"
- [ ] View pending request with all fields
- [ ] Click "Approve"
- [ ] Verify in database:
  - [ ] Tenant record updated with room_id
  - [ ] Additional tenant records created (if occupancy > 1)
  - [ ] Room status changed to 'occupied'
  - [ ] Request status changed to 'approved'
  - [ ] Approved timestamp recorded

### Display Test
- [ ] Rooms page shows correct occupancy count
- [ ] Room type dropdown has Single, Shared, Bedspace options
- [ ] Occupancy reports show:
  - [ ] Total tenants count
  - [ ] Tenant names in room listing
  - [ ] Tenant count per room
  - [ ] Proper date calculations

### Edge Cases
- [ ] Request room that's already occupied (should show occupied status)
- [ ] Try to request same room twice (should reject as duplicate)
- [ ] Approve request with minimum occupancy (1 person)
- [ ] Approve request with maximum occupancy (4 people)
- [ ] Edit room - change type from Single to Shared and back

## User Documentation

- [ ] Provide tenant users with "ROOM_OCCUPANCY_QUICK_START.md"
- [ ] Provide admin users with full "ROOM_OCCUPANCY_IMPLEMENTATION.md"
- [ ] Train admins on new approval workflow
- [ ] Explain occupancy limits to tenants

## Post-Deployment

### Monitoring
- [ ] Monitor admin dashboard for any errors
- [ ] Check error logs for database issues
- [ ] Verify email notifications work (if applicable)
- [ ] Monitor room requests volume

### Documentation
- [ ] Update main README if applicable
- [ ] Add troubleshooting section to help desk
- [ ] Create quick reference cards for common questions
- [ ] Document any custom modifications made

## Rollback Plan

If issues occur:

1. **Restore Database**
   - Stop all active users from making requests
   - Restore database from backup
   - Clear all rejected/approved requests from testing phase

2. **Revert Code** (if needed)
   - Restore original PHP files from version control
   - Clear browser caches
   - Restart PHP-FPM/Apache

3. **Communication**
   - Notify users of issue
   - Provide timeline for resolution
   - Keep users updated

## Sign-Off

### Developer
- Name: _______________
- Date: _______________
- Notes: _______________

### QA Lead
- Name: _______________
- Date: _______________
- All tests passed: ☐ Yes ☐ No

### System Admin
- Name: _______________
- Date: _______________
- Production ready: ☐ Yes ☐ No

### Project Manager
- Name: _______________
- Date: _______________
- Deployment approved: ☐ Yes ☐ No

## Known Limitations & Future Work

### Current Limitations
- Additional occupants initially get same name as primary tenant
- Occupancy limits not enforced if records are created manually outside this workflow
- No bulk occupant name input during request

### Future Enhancements
- [ ] Allow occupant names during request submission
- [ ] Add room change/transfer requests
- [ ] Implement occupancy history tracking
- [ ] Add tenant move-out workflow
- [ ] Email notifications for request status changes

---

**Document Version:** 1.0
**Last Updated:** January 2026
**Status:** Ready for Deployment
