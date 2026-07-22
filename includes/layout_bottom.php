    </main>
  </div>
</div>

<script>
document.getElementById('menuToggle')?.addEventListener('click', function () {
  document.getElementById('sidebar').classList.toggle('open');
});
// Close user menu / any modal when clicking outside the box
document.addEventListener('click', function (e) {
  document.querySelectorAll('.modal-overlay.open').forEach(function (ov) {
    if (e.target === ov) ov.classList.remove('open');
  });
});
</script>
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
</body>
</html>
