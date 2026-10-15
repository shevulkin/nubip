// Кнопки «Копіювати»: текст дня лежить у data-copy, щоб не залежати від виділення мишкою
document.addEventListener('click', function (e) {
  var b = e.target.closest && e.target.closest('[data-copy]');
  if (!b) return;
  var text = b.getAttribute('data-copy');
  var done = function () {
    var old = b.textContent;
    b.textContent = '✓ Скопійовано';
    setTimeout(function () { b.textContent = old; }, 1800);
  };
  var fallback = function () {
    var t = document.createElement('textarea');
    t.value = text; t.style.position = 'fixed'; t.style.opacity = '0';
    document.body.appendChild(t); t.select();
    try { document.execCommand('copy'); done(); } catch (err) { window.prompt('Скопіюйте текст:', text); }
    document.body.removeChild(t);
  };
  if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(done, fallback);
  else fallback();
});
