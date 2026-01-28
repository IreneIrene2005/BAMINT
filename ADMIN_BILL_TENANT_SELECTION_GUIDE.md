# Admin Billing - Tenant Selection Enhancement Guide

## Overview
The Admin Billing page has been enhanced with a new "Add Specific Tenant Bill" feature that allows admins to easily add bills for individual tenants while viewing all relevant tenant information.

## Features Added

### 1. **New "Add Specific Tenant Bill" Button**
- **Location**: Top right of the Monthly Billing section
- **Icon**: Person Plus icon (👤+)
- **Color**: Blue primary button
- **Next to**: "Generate Bills" button
- Opens the "Add Manual Bill" modal with enhanced features

### 2. **Enhanced Tenant Selection Dropdown**
- Shows all active tenants in alphabetical order
- Displays tenant name clearly in the dropdown list
- Click to select a tenant

### 3. **Tenant Details Card**
When a tenant is selected, a card automatically displays:

**Left Column:**
- **Room Number** - The specific room assigned to the tenant
- **Room Type** - Type of room (Single, Double, etc.)

**Right Column:**
- **Monthly Rate** - The room's monthly rental price (in ₱)
- **Move-in Date** - When the tenant moved in (formatted date)

### 4. **Smart Auto-Fill**
- **Amount Due field** automatically fills with the tenant's monthly room rate
- **Billing Month** defaults to current month
- **Due Date** defaults to the 15th of the current month
- User can override any auto-filled values if needed

### 5. **Click-to-Fill Feature**
- Click on the **Monthly Rate** value in the details card
- Automatically fills the "Amount Due" field with that rate
- Useful if manually changed or to quickly reset

## How to Use

### Step 1: Click "Add Specific Tenant Bill" Button
1. Go to **Admin → Billing** page
2. Click the blue **"Add Specific Tenant Bill"** button
3. The "Add Manual Bill" modal opens

### Step 2: Select a Tenant
1. Click the "Select Tenant" dropdown
2. View the list of all active tenants
3. Click on the tenant you want to add a bill for
4. The tenant details card will appear below the dropdown

### Step 3: Review Tenant Details
The tenant details card shows:
- Which room they're in (Room Number)
- What type of room (Room Type)
- How much they pay monthly (Monthly Rate) 💰
- When they moved in (Move-in Date) 📅

### Step 4: Configure Bill Details
1. **Billing Month** - Pre-filled with current month, change if needed
2. **Amount Due** - Pre-filled with the tenant's monthly rate
   - Click on the Monthly Rate in the card to quickly fill this
   - Can be adjusted for special charges or discounts
3. **Due Date** - Pre-filled with the 15th of the month
   - Change to match your billing cycle

### Step 5: Save Bill
1. Review all details
2. Click **"Save Bill"** button
3. Bill is created and linked to the selected tenant
4. Modal closes and you return to the bill list

## Benefits

✅ **No More Guessing** - See room details before creating bill  
✅ **Faster Data Entry** - Amounts auto-fill from room rates  
✅ **Accurate Billing** - Reduces manual entry errors  
✅ **Better Organization** - Separate buttons for batch vs. individual bills  
✅ **Time Saving** - Auto-fill functionality saves time  
✅ **Visual Confirmation** - See tenant details before committing  

## Use Cases

### Case 1: Monthly Recurring Bills
1. Click "Add Specific Tenant Bill"
2. Select tenant
3. Use auto-filled amount (monthly rate)
4. Click Save

### Case 2: Late Fees or Additional Charges
1. Click "Add Specific Tenant Bill"
2. Select tenant
3. Auto-fill shows monthly rate
4. Increase amount for late fees
5. Click Save

### Case 3: Correcting a Missed Bill
1. Click "Add Specific Tenant Bill"
2. Select tenant (already see their details)
3. Change billing month to previous month
4. Use auto-filled amount
5. Click Save

### Case 4: One-Time Charges
1. Click "Add Specific Tenant Bill"
2. Select tenant
3. Auto-fill shows monthly rate
4. Change amount to the one-time charge
5. Update billing month if needed
6. Click Save

## Technical Details

### Database Query
The tenant dropdown pulls:
- Tenant ID, Name
- Room Assignment (Room Number, Room Type)
- Move-in Date (start_date)
- Monthly Rate from assigned room

```sql
SELECT t.id, t.name, t.start_date, r.room_type, r.rate, r.room_number 
FROM tenants t 
LEFT JOIN rooms r ON t.room_id = r.id 
WHERE t.status = 'active' 
ORDER BY t.name ASC
```

### JavaScript Functions
- `loadTenantDetails()` - Fetches and displays tenant info when selected
- Auto-fills the Amount Due field with the room rate
- Formats move-in date for readability
- Shows/hides the details card based on selection

### Data Attributes
Each option in the dropdown stores:
- `data-room-number` - Room number
- `data-room-type` - Room type
- `data-rate` - Monthly rate
- `data-move-in` - Move-in date

## Default Values

| Field | Default | Can Change |
|-------|---------|-----------|
| Tenant | (none - must select) | Yes |
| Billing Month | Current month | Yes |
| Amount Due | Room's monthly rate | Yes |
| Due Date | 15th of current month | Yes |

## Related Features

### Generate Monthly Bills (Batch)
- Use when creating bills for **all active tenants** at once
- Everyone gets same month and due date
- All amounts based on room rates
- Faster for monthly billing cycle

### Add Specific Tenant Bill (Individual)
- Use when adding bill for **one tenant** only
- Can customize amounts and dates
- Great for late additions or corrections
- Shows full tenant details before saving

## Troubleshooting

### Tenant Details Card Not Showing
- **Solution**: Make sure you selected a tenant from the dropdown
- The card only appears after selection

### Amount Due Not Filling Automatically
- **Solution**: Check if the tenant has a room assigned
- Tenants without rooms will show N/A for rate
- You can manually enter the amount

### Dropdown Not Showing Tenants
- **Solution**: Only active tenants appear in the list
- Check if tenant status is set to "active"
- Inactive tenants are hidden for clarity

### Date Format Looks Wrong
- **Solution**: Uses locale-specific formatting (MM/DD/YYYY in US)
- Date is correctly stored in database
- Formatting is just for display

## Related Documentation

- [Bills Management Guide](BILLS_MANAGEMENT.md)
- [Admin Billing Features](BILLING_FEATURES.md)
- [Tenant Management](TENANT_MANAGEMENT.md)
- [Room Management](ROOM_MANAGEMENT.md)

## Summary

The enhanced "Add Specific Tenant Bill" feature makes billing faster and more accurate by:
1. Allowing selection of specific tenants
2. Displaying all relevant room and tenant information
3. Auto-filling common fields to save time
4. Providing one-click confirmation with complete information
5. Supporting both regular and special billing scenarios

---

**Last Updated**: January 28, 2026  
**Status**: Feature Complete ✅  
**Version**: 1.0
