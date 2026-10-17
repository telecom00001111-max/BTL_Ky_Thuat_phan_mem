// Xác nhận trước các thao tác quan trọng: <form data-confirm="Nội dung hỏi">
document.addEventListener('submit', function (ev) {
  var f = ev.target.closest('form[data-confirm]');
  if (f && !window.confirm(f.getAttribute('data-confirm'))) ev.preventDefault();
});
// Tự đặt con trỏ vào ô có thuộc tính autofocus-scan
var s = document.querySelector('[data-autofocus]');
if (s) s.focus();
