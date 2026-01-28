# Tenant Bills Archive Feature Guide

## Overview
The tenant bills page now includes an archive feature that automatically separates paid bills older than 6 months from active bills.

## Features Implemented

### 1. **Archive Tab Navigation**
- Location: Above the filter section in tenant_bills.php
- Shows two tabs:
  - **Active Bills**: Currently active and recent bills
  - **Archive**: Paid bills older than 6 months with badge counter

### 2. **Dual Query System**
**Active Bills Query:**
```sql
SELECT * FROM bills 
WHERE tenant_id = :tenant_id 
AND NOT (status = 'paid' AND DATE_ADD(updated_at, INTERVAL 6 MONTH) < NOW())
ORDER BY billing_month DESC
```

**Archive Bills Query:**
```sql
SELECT * FROM bills 
WHERE tenant_id = :tenant_id 
AND status = 'paid' 
AND DATE_ADD(updated_at, INTERVAL 6 MONTH) < NOW()
ORDER BY billing_month DESC
```

### 3. **Tab Switching**
- JavaScript `switchView()` function handles switching between active and archive views
- Updates URL with `view` parameter to maintain state
- Smooth display toggle with no page reload
- Button styling updates to show active tab

### 4. **Archive View Display**
- Archived bills shown in read-only card format
- Displays billing month, amount due, amount paid, discount, and payment date
- Progress bar shows 100% completion (all paid)
- "Archived" badge indicates the bill is in the archive
- Notes displayed if available

## User Experience

### How Tenants Use It
1. Click on **"Active Bills"** tab to see current and recent bills
2. Click on **"Archive"** tab to view paid bills from 6+ months ago
3. Filter options apply to the active bills view
4. Archived bills are read-only (no payment or editing options)

### Visual Indicators
- **Tab Badge**: Shows count of archived bills when > 0
- **Archive Badge**: Green badge with checkmark on archived bills
- **Progress Bar**: 100% filled (teal) for archived paid bills
- **Timestamp**: Shows payment date for archived bills

## Technical Implementation

### Files Modified
- **tenant_bills.php** (537 lines)
  - Added `$view` variable to track active/archive state
  - Added `$archive_bills` array for archived bills
  - Dual query system for filtering
  - Tab navigation HTML with Bootstrap styles
  - Separate div containers with display toggle
  - JavaScript switchView() function

### Key Differences from Admin Archive
| Feature | Admin Bills (7 days) | Tenant Bills (6 months) |
|---------|-------------------|----------------------|
| Interval | INTERVAL 7 DAY | INTERVAL 6 MONTH |
| View Type | Table format | Card format |
| Filter Support | Applied to active only | Applied to active only |
| Edit/Delete | Not allowed in archive | Not allowed in archive |

### Database Query Mechanism
- Uses `DATE_ADD(updated_at, INTERVAL 6 MONTH)` to calculate archive threshold
- Compares with `NOW()` to determine if bill is older than 6 months
- `updated_at` column automatically tracks when bill status changed
- No schema modifications required

## URL Parameters
- `view=active` - Show active bills (default)
- `view=archive` - Show archived bills
- `status=pending|unpaid|overdue|paid` - Filter by status (active view only)

### Example URLs:
- `tenant_bills.php` - Shows active bills (default)
- `tenant_bills.php?view=archive` - Shows archived bills
- `tenant_bills.php?view=active&status=unpaid` - Shows unpaid active bills

## Archive Logic Timeline

### When a Bill Gets Archived
1. Bill is marked as "paid" with a paid_date
2. 6 months pass from the updated_at timestamp
3. On next page load, the bill moves to archive query results
4. Bill automatically appears in Archive tab

### Re-activating Bills (if needed)
If a bill's status is changed from "paid" to something else, it immediately reappears in the active view (since the archive query requires `status = 'paid'`).

## UI/UX Notes
- Archive view shows the same card layout as active bills for consistency
- No filtering options shown in archive view (can be added if needed)
- Badges update dynamically based on actual archived bill count
- Empty state message shown if no bills exist in either view

## Testing Checklist
- [ ] Click between Active Bills and Archive tabs
- [ ] Verify URL updates with view parameter
- [ ] Check that paid bills older than 6 months appear in archive
- [ ] Verify archive badge count matches actual archived bills
- [ ] Test filter functionality on active bills only
- [ ] Check that archived bills don't have edit/delete options
- [ ] Verify empty state message when no bills exist
- [ ] Test on mobile (cards should stack properly)

## Troubleshooting

### Archive Appears Empty
- Check that bills are marked as "paid" status
- Verify bill's `updated_at` is older than 6 months (182+ days)
- Check database timestamp accuracy

### Archive Tab Not Showing
- Ensure bills table has `updated_at` column
- Check database connection is working
- Review browser console for JavaScript errors

### Switching Between Tabs Not Working
- Check JavaScript console for errors
- Verify `switchView()` function is loaded
- Ensure Bootstrap CSS is loaded for button styling
