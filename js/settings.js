(function (Drupal, once) {
  Drupal.behaviors.logWatcherSeverityControls = {
    attach: function (context) {
      once('log-watcher-severity-controls', '[data-log-watcher-severity-action]', context).forEach(function (button) {
        button.addEventListener('click', function () {
          var checked = button.dataset.logWatcherSeverityAction === 'select';
          var form = button.closest('form');

          if (!form) {
            return;
          }

          form.querySelectorAll('input[name^="monitoring[severity_levels]"]').forEach(function (input) {
            input.checked = checked;
          });
        });
      });
    }
  };
})(Drupal, once);
