window.PASS50_API = {
  // Laisser './api' si le dossier api est placé à côté de index.html.
  baseUrl: './api'
};

// Liens légaux publics visibles dans le pied de page du site.
document.addEventListener('DOMContentLoaded', function () {
  var footer = document.querySelector('.footer');
  if (!footer || document.getElementById('pass50LegalLinks')) return;

  var legal = document.createElement('div');
  legal.id = 'pass50LegalLinks';
  legal.setAttribute('aria-label', 'Informations légales PASS50');
  legal.style.display = 'flex';
  legal.style.flexWrap = 'wrap';
  legal.style.justifyContent = 'center';
  legal.style.gap = '10px 16px';
  legal.style.width = '100%';
  legal.style.marginTop = '12px';
  legal.style.fontSize = '12px';
  legal.style.color = '#cbd3c8';
  legal.innerHTML = [
    '<a href="./conditions-utilisation.html" style="text-decoration:underline;text-underline-offset:3px">Conditions d’utilisation</a>',
    '<a href="./politique-confidentialite.html" style="text-decoration:underline;text-underline-offset:3px">Politique de confidentialité</a>',
    '<a href="./informations-legales.html" style="text-decoration:underline;text-underline-offset:3px">Informations légales</a>',
    '<a href="./verification-pass50.html" style="text-decoration:underline;text-underline-offset:3px">Vérification PASS50</a>'
  ].join('');
  footer.appendChild(legal);
});

// Fonctionnalités des fiches influenceurs : like, partage, badge et partage des lives.
(function () {
  if (document.querySelector('script[data-pass50-fi-engagement]')) return;
  var script = document.createElement('script');
  script.src = './fi-engagement-v2.js?v=3.2';
  script.dataset.pass50FiEngagement = '3.2';
  document.head.appendChild(script);
})();

// Nettoyage des formulations internes dans l'affichage public.
(function () {
  if (document.querySelector('script[data-pass50-public-copy]')) return;
  var script = document.createElement('script');
  script.src = './public-copy-fixes.js?v=1.0';
  script.dataset.pass50PublicCopy = '1.0';
  document.head.appendChild(script);
})();

// Navigation continue entre les fiches influenceurs.
(function () {
  if (document.querySelector('script[data-pass50-fi-navigation]')) return;
  var script = document.createElement('script');
  script.src = './fi-navigation.js?v=1.2';
  script.dataset.pass50FiNavigation = '1.2';
  document.head.appendChild(script);
})();
