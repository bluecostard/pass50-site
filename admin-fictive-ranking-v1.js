(function () {
  'use strict';

  function removeFictiveRankingBanner() {
    document
      .querySelectorAll('[data-pass50-fictive-ranking]')
      .forEach(function (banner) {
        banner.remove();
      });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', removeFictiveRankingBanner);
  } else {
    removeFictiveRankingBanner();
  }
})();
