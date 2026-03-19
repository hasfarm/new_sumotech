# DEBUG UPLOAD ERRORS

## Cách xem lỗi chi tiết:

### 1. Xem Console Log
```
1. Mở DevTools (F12)
2. Tab Console
3. Upload file
4. Tìm dòng: "Upload response: ..."
5. Copy toàn bộ response
```

### 2. Xem Network Response
```
1. DevTools → Network tab
2. Upload file
3. Click request "upload" (màu đỏ)
4. Tab Response → copy nội dung
```

### 3. Xem Laravel Log
```bash
tail -50 storage/logs/laravel-$(date +%Y-%m-%d).log
```

## Các lỗi thường gặp:

### Lỗi: "The images field is required"
→ File không được gửi đúng cách
→ Thử chọn lại file

### Lỗi: "The images.0 must be an image"
→ File không phải ảnh hoặc định dạng không hỗ trợ
→ Chỉ chấp nhận: JPG, PNG, WEBP

### Lỗi: "The images.0 may not be greater than 10240 kilobytes"
→ File quá lớn (> 10MB)
→ Giảm kích thước ảnh

### Lỗi: HTTP 500
→ Xem log Laravel để biết chi tiết
→ Có thể do quyền thư mục hoặc session hết hạn

## Test upload thủ công:

```bash
# Test với script PHP (backend OK)
php test_upload_media.php 47

# Test với curl
curl -X POST http://sumotech.ai/audiobooks/47/media/upload \
  -H "Cookie: your_session_cookie" \
  -H "X-CSRF-TOKEN: your_csrf_token" \
  -F "type=thumbnails" \
  -F "images[]=@/path/to/image.jpg"
```

## Giải pháp:

1. **Hard refresh** trang (Ctrl+Shift+R)
2. **Đăng nhập lại**
3. Thử upload **1 file nhỏ** (< 1MB) trước
4. Kiểm tra **định dạng file** (JPG, PNG, WEBP)
