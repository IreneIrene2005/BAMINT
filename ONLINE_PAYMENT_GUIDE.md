# Online Payment File Upload - Step-by-Step Guide

## The Issue You Encountered

Error: **"Proof of payment is required for online payments"**

This means the form didn't receive the file you tried to upload.

---

## ✅ SOLUTION: How to Upload Proof Correctly

### **Step 1: Select Payment Method FIRST** ⭐ IMPORTANT
When you first open the payment form, you'll see **two method cards**:
- **Online Payment** (blue card)
- **Walk-in Payment** (green card)

**👉 Click on "Online Payment" card** to select it.

This is **CRITICAL** - you must select Online Payment before the file upload field appears.

### **Step 2: Form Updates**
Once you click Online Payment:
- The card will be highlighted (blue border)
- A new field appears: **"Proof of Payment"** 
- This is where you upload your file

### **Step 3: Fill Required Fields**
Complete all required fields:
1. **Select Bill** - Choose the bill you're paying
2. **Payment Amount** - Enter amount
3. **Payment Method** - Choose how you paid (GCash, Bank Transfer, PayMaya, Check, Cash)
4. **Proof of Payment** - Choose your file (see Step 4)

### **Step 4: Upload Your Proof File**
1. Click on the **"Proof of Payment"** field (file picker)
2. A dialog box opens - select a file from your computer:
   - ✅ Screenshot of transaction (JPG, PNG)
   - ✅ PDF document of receipt
   - ✅ Photo of bank confirmation
3. **File requirements**:
   - Type: JPG, PNG, or PDF only
   - Size: Under 5MB
4. Click **Open** to select the file
5. Filename should appear in the field

### **Step 5: Submit**
- Click **"Submit Payment"** button
- You should see: **"Online payment submitted!"** ✓
- Status will show: **"Awaiting Verification"**

---

## Common Mistakes

### ❌ Mistake 1: Not Selecting Online Payment
**What happens**: File upload field never appears
**Fix**: Click the blue "Online Payment" card FIRST

### ❌ Mistake 2: Form Data Missing
**What happens**: Error "Proof of payment is required"
**Fix**: 
- Make sure you selected a payment method ✓
- Make sure you selected a file ✓
- Make sure you selected a bill ✓

### ❌ Mistake 3: Wrong File Type
**What happens**: Error "Only JPG, PNG, and PDF files allowed"
**Fix**: Use JPG, PNG, or PDF - no other formats

### ❌ Mistake 4: File Too Large
**What happens**: Error "File size must be less than 5MB"
**Fix**: Compress the image or take a new screenshot

### ❌ Mistake 5: Browser Cache Issue
**What happens**: Form behaves strangely
**Fix**: 
- Press Ctrl + F5 (refresh with cache clear)
- Try a different browser
- Try again

---

## File Upload Checklist

Before clicking Submit, verify:

```
[ ] Online Payment method is selected (blue card is highlighted)
[ ] I selected a bill from the dropdown
[ ] I entered a payment amount
[ ] I selected a payment method (GCash, Bank Transfer, etc.)
[ ] I selected a file (JPG, PNG, or PDF)
[ ] File is less than 5MB
[ ] Filename appears in the "Proof of Payment" field
```

If all checked ✓, then click **Submit Payment**

---

## Expected Behavior

### When You Submit
✅ Success message: **"Online payment submitted!"**

### In Your Payment History
📋 Status: **"Awaiting Verification"** (orange/yellow)

### Admin Reviews (Usually same day)
✅ Status changes to: **"Verified"** (green)

### After Verification
- Status: **"Approved"** (green)
- Bill balance updates
- You can see payment details

---

## What Admin Sees

Your uploaded proof file goes to admin's **Payment Verification Dashboard** where admin can:
- ✓ View your uploaded proof
- ✓ Verify the payment amount
- ✓ Check payment details
- ✓ Approve the payment

---

## Example: Complete Workflow

### You Submit:
- **Bill**: June 2024 - ₱1,500.00 due
- **Amount**: ₱1,500.00
- **Method**: GCash
- **Proof**: Screenshot of GCash transaction confirmation

### Admin Sees:
- Tenant: [Your Name]
- Bill: June 2024
- Amount: ₱1,500.00
- Method: GCash
- Proof: [Your uploaded screenshot]
- Status: Pending Review

### Admin Approves:
- ✓ Clicks "Approve"
- Status changes to "Verified"
- Your payment is confirmed

### You See:
- ✓ Payment in "Verified" section
- ✓ Bill marked as paid
- ✓ Receipt available for download

---

## If It Still Doesn't Work

### Debug Steps:
1. **Check browser console**: 
   - Press F12 → Console tab
   - Try submitting
   - Look for error messages
   - Screenshot and share

2. **Try different browser**:
   - Edge/Chrome/Firefox
   - See if it works in another browser

3. **Clear browser cache**:
   - Ctrl + Shift + Delete
   - Clear browsing data
   - Reload page
   - Try again

4. **Contact office**:
   - Email or call office admin
   - Mention you see "Proof of payment is required" error
   - Describe which browser you're using

---

## System Information

✅ **Form Encoding**: Properly configured for file uploads
✅ **Accepted Files**: JPG, PNG, PDF (max 5MB each)
✅ **Storage**: Files saved securely on server
✅ **Admin Access**: Only office admin can view uploads
✅ **Privacy**: Your uploaded proof is confidential

---

## Quick Summary

| Step | Action | Result |
|------|--------|--------|
| 1 | Click "Online Payment" card | File upload field appears |
| 2 | Select bill from dropdown | Amount hint shows |
| 3 | Enter payment amount | Submit button enables |
| 4 | Choose payment method | Required field filled |
| 5 | Click file picker → select file | Filename appears |
| 6 | Click "Submit Payment" | Proof submitted ✓ |
| 7 | Admin reviews proof | Status: Verified ✓ |

---

**Try it now!** 🎉

If you're still seeing "Proof of payment is required" error after following these steps, please contact the office admin.

---

*Last Updated: Today*
*System: BAMINT Tenant Management*
