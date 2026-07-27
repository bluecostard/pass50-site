window.PASS50_API = {
  // Laisser './api' si le dossier api est placé à côté de index.html.
  baseUrl: './api'
};

// Les anciennes sauvegardes lançaient plusieurs écritures concurrentes et pouvaient
// réécrire l'état avec une version incomplète. Le module transactionnel V3 les remplace.
try {
  localStorage.setItem('pass50_v227_confirmed_links_backup', '1');
  localStorage.setItem('pass50_v226_nolimit_links_seeded', '1');
} catch (_) {}

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
  script.src = './fi-engagement-v3.js?v=1.0';
  script.dataset.pass50FiEngagement = '3.0';
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
  script.src = './fi-navigation-v3.js?v=1.0';
  script.dataset.pass50FiNavigation = '3.0';
  document.head.appendChild(script);
})();

// Profil recensé : Ennemi des Djandjou.
(function () {
  if (document.querySelector('script[data-pass50-profile-ennemi-djandjou]')) return;
  var script = document.createElement('script');
  script.src = './profile-ennemi-des-djandjou.js?v=1.0';
  script.dataset.pass50ProfileEnnemiDjandjou = '1.0';
  document.head.appendChild(script);
})();

// Profil recensé : Kawaii Nanami.
(function () {
  if (document.querySelector('script[data-pass50-profile-kawaii-nanami]')) return;
  var script = document.createElement('script');
  script.src = './profile-kawaii-nanami.js?v=1.0';
  script.dataset.pass50ProfileKawaiiNanami = '1.0';
  document.head.appendChild(script);
})();

// Radar LIVE V3 : balayage complet de tous les liens officiels validés.
(function () {
  if (document.querySelector('script[data-pass50-live-radar]')) return;
  var script = document.createElement('script');
  script.src = './live-radar-v3.js?v=1.1';
  script.dataset.pass50LiveRadar = '3.1';
  document.head.appendChild(script);
})();

// Sauvegarde transactionnelle et restauration automatique des liens officiels.
(function () {
  if (document.querySelector('script[data-pass50-official-links-persistence]')) return;
  var script = document.createElement('script');
  script.src = './official-links-persistence-v3.js?v=3.1';
  script.dataset.pass50OfficialLinksPersistence = '3.1';
  document.head.appendChild(script);
})();
