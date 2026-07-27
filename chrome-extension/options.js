const baseUrlInput = document.getElementById('baseUrl');
const tokenInput = document.getElementById('token');
const statusEl = document.getElementById('status');

chrome.storage.sync.get(['baseUrl', 'token'], (data) => {
    baseUrlInput.value = data.baseUrl || 'http://sumotech.test';
    tokenInput.value = data.token || '';
});

document.getElementById('save').addEventListener('click', () => {
    const baseUrl = baseUrlInput.value.trim().replace(/\/+$/, '');
    const token = tokenInput.value.trim();

    if (!baseUrl || !token) {
        statusEl.textContent = 'Cần nhập đủ App URL và Token.';
        statusEl.style.color = '#dc2626';
        return;
    }

    chrome.storage.sync.set({ baseUrl, token }, () => {
        statusEl.textContent = '✅ Đã lưu!';
        statusEl.style.color = '#059669';
        setTimeout(() => { statusEl.textContent = ''; }, 2500);
    });
});
