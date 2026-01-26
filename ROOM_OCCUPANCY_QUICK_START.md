# Room Occupancy System - Quick Start Guide

## What's New?

### For Tenants 👥
When requesting a room, you now need to provide:
1. **Full Name** - Your complete name
2. **Email** - Valid email address
3. **Phone Number** - Contact phone
4. **Address** - Your address
5. **Number of Occupants** - How many people will live in the room

**Important:** The number of occupants you select must match the room type limit:
- **Single Room** = Max 1 person only
- **Shared Room** = Max 2 people
- **Bedspace Room** = Max 4 people

### For Admins 👨‍💼
When you approve a room request:
- ✅ Tenant is automatically assigned to the room
- ✅ Additional occupant records are created if needed
- ✅ Room status changes to "Occupied"
- ✅ Request is marked as "Approved"

## How It Works

### Scenario: 3 People Request a Bedspace Room

**Step 1: Tenant Submits Request**
- Selects Bedspace Room
- Enters name: "Juan Dela Cruz"
- Enters email: "juan@email.com"
- Enters phone: "09123456789"
- Enters address: "123 Main St"
- **Selects 3 occupants** (valid - bedspace allows up to 4)

**Step 2: Admin Reviews**
- Goes to Room Requests Queue
- Sees the request with all information
- Clicks "Approve"

**Step 3: System Automatically Creates**
- Tenant 1: Juan Dela Cruz (in the room, active)
- Tenant 2: Juan Dela Cruz - Occupant 2 (auto-created)
- Tenant 3: Juan Dela Cruz - Occupant 3 (auto-created)
- Room status: Changes to "Occupied"
- Request status: "Approved"

**Step 4: Room is Now Unavailable**
- Room no longer appears in "Available Rooms" list
- Cannot be requested by other tenants
- Shows in occupancy reports with 3 occupants

## Room Types Reference

| Type | Max People | Usage |
|------|-----------|-------|
| Single | 1 | Individual tenants |
| Shared | 2 | Couples or roommates |
| Bedspace | 4 | Groups or families |

## Key Pages

### For Tenants
- **Add Room** - Submit room requests with information
- **My Requests** - View status of your room requests

### For Admins
- **Room Requests Queue** - Review and approve/reject requests
- **Rooms** - Manage room details and view occupancy
- **Occupancy Reports** - View tenant counts and statistics

## Important Notes

⚠️ **Occupancy Validation**
- You cannot request more people than the room type allows
- The system will reject invalid requests

⚠️ **Request Duplicate Prevention**
- You cannot have multiple pending requests for the same room
- Wait for admin decision before requesting another room

⚠️ **Room Availability**
- Once approved, room becomes "Occupied"
- It won't be available for new requests
- Admin must change status to "Available" if needed

## Troubleshooting

**Q: Why can't I request 4 people for a Shared room?**
A: Shared rooms only allow 2 people maximum. Select a Bedspace room instead.

**Q: Will all 3 occupants be named the same?**
A: Initially yes. Admin can edit additional occupants' names in the Tenants section.

**Q: What if I make a mistake in my request?**
A: Ask admin to reject the request, then submit a new one.

**Q: Can the number of occupants be changed after approval?**
A: Yes, admin can edit individual tenant records in the Tenants section.

---

**Need Help?** Contact your admin for any issues with room requests.
