</main>
<footer class="admin-footer text-center small py-3">
    <?= e(APP_NAME) ?> &middot; Shubh Deepavali ✨
</footer>
<script src="<?= e(url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
<?php if (!empty($pageScripts)): foreach ($pageScripts as $script): ?>
<script src="<?= e(url($script)) ?>"></script>
<?php endforeach; endif; ?>
</body>
</html>
