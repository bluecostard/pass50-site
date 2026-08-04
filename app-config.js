window.PASS50_API = {
  // Laisser './api' si le dossier api est placé à côté de index.html.
  baseUrl: './api'
};

// Centre de partage unifié : doit être chargé avant les anciens gestionnaires.
(function () {
  if (document.querySelector('script[data-pass50-share-center]')) return;
  var script = document.createElement('script');
  script.src = './share-center-v1.js?v=1.0';
  script.async = false;
  script.dataset.pass50ShareCenter = '1.0';
  document.head.appendChild(script);
})();

// Interface simplifiée du partage « Les Coulés ».
(function () {
  if (document.querySelector('script[data-pass50-coules-share-simple]')) return;
  var script = document.createElement('script');
  script.src = './coules-share-simple-v1.js?v=1.0';
  script.async = false;
  script.dataset.pass50CoulesShareSimple = '1.0';
  document.head.appendChild(script);
})();

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
  script.src = './fi-engagement-v3.js?v=1.3';
  script.dataset.pass50FiEngagement = '3.3';
  document.head.appendChild(script);
})();

// Mise en page responsive de la fenêtre « En direct maintenant ».
(function () {
  if (document.querySelector('script[data-pass50-live-modal-layout]')) return;
  var script = document.createElement('script');
  script.src = './live-modal-layout-v1.js?v=1.0';
  script.dataset.pass50LiveModalLayout = '1.0';
  document.head.appendChild(script);
})();

// Nettoyage des formulations internes dans l'affichage public.
(function () {
  if (document.querySelector('script[data-pass50-public-copy]')) return;
  var script = document.createElement('script');
  script.src = './public-copy-fixes.js?v=1.1';
  script.dataset.pass50PublicCopy = '1.1';
  document.head.appendChild(script);
})();

// Navigation continue entre les fiches influenceurs.
(function () {
  if (document.querySelector('script[data-pass50-fi-navigation]')) return;
  var script = document.createElement('script');
  script.src = './fi-navigation-v3.js?v=1.2';
  script.dataset.pass50FiNavigation = '3.2';
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

// Profil recensé : Mélanie TMS.
(function () {
  if (document.querySelector('script[data-pass50-profile-melanie-tms]')) return;
  var script = document.createElement('script');
  script.src = './profile-melanie-tms.js?v=1.0';
  script.dataset.pass50ProfileMelanieTms = '1.0';
  document.head.appendChild(script);
})();

// Profil recensé : Ivorian Kid.
(function () {
  if (document.querySelector('script[data-pass50-profile-ivorian-kid]')) return;
  var script = document.createElement('script');
  script.src = './profile-ivorian-kid.js?v=1.0';
  script.dataset.pass50ProfileIvorianKid = '1.0';
  document.head.appendChild(script);
})();

// Profil recensé : OBRE / Marie-Pascale Obré.
(function () {
  if (document.querySelector('script[data-pass50-profile-obre-marie-pascale]')) return;
  var script = document.createElement('script');
  script.src = './profile-obre-marie-pascale.js?v=1.0';
  script.dataset.pass50ProfileObreMariePascale = '1.0';
  document.head.appendChild(script);
})();

// Profil recensé : Oustaz Diané.
(function () {
  if (document.querySelector('script[data-pass50-profile-oustaz-diane]')) return;
  var script = document.createElement('script');
  script.src = './profile-oustaz-diane.js?v=1.0';
  script.dataset.pass50ProfileOustazDiane = '1.0';
  document.head.appendChild(script);
})();

// Profil recensé : Ismaël Aka.
(function () {
  if (document.querySelector('script[data-pass50-profile-ismael-aka]')) return;
  var script = document.createElement('script');
  script.src = './profile-ismael-aka.js?v=1.0';
  script.dataset.pass50ProfileIsmaelAka = '1.0';
  document.head.appendChild(script);
})();

// Profil recensé : Général Camille Makosso.
(function () {
  if (document.querySelector('script[data-pass50-profile-general-camille-makosso]')) return;
  var script = document.createElement('script');
  script.src = './profile-general-camille-makosso.js?v=1.0';
  script.dataset.pass50ProfileGeneralCamilleMakosso = '1.0';
  document.head.appendChild(script);
})();

// Profil recensé : Lolo Beauté.
(function () {
  if (document.querySelector('script[data-pass50-profile-lolo-beaute]')) return;
  var script = document.createElement('script');
  script.src = './profile-lolo-beaute.js?v=1.0';
  script.dataset.pass50ProfileLoloBeaute = '1.0';
  document.head.appendChild(script);
})();

// Profil recensé : Kim Makosso.
(function () {
  if (document.querySelector('script[data-pass50-profile-kim-makosso]')) return;
  var script = document.createElement('script');
  script.src = './profile-kim-makosso.js?v=1.0';
  script.dataset.pass50ProfileKimMakosso = '1.0';
  document.head.appendChild(script);
})();

// Profil recensé : Dez Cocrane 225.
(function () {
  if (document.querySelector('script[data-pass50-profile-dez-cocrane225]')) return;
  var script = document.createElement('script');
  script.src = './profile-dez-cocrane225.js?v=1.0';
  script.dataset.pass50ProfileDezCocrane225 = '1.0';
  document.head.appendChild(script);
})();

// Profil recensé : Atoulé.
(function () {
  if (document.querySelector('script[data-pass50-profile-atoule]')) return;
  var script = document.createElement('script');
  script.src = './profile-atoule.js?v=1.0';
  script.dataset.pass50ProfileAtoule = '1.0';
  document.head.appendChild(script);
})();

// Radar LIVE V4 : balayage continu de tous les liens officiels validés.
(function () {
  if (document.querySelector('script[data-pass50-live-radar]')) return;
  var script = document.createElement('script');
  script.src = './live-radar-v3.js?v=1.7';
  script.dataset.pass50LiveRadar = '4.4';
  document.head.appendChild(script);
})();

// Sauvegarde transactionnelle et restauration automatique des liens officiels.
(function () {
  if (document.querySelector('script[data-pass50-official-links-persistence]')) return;
  var script = document.createElement('script');
  script.src = './official-links-persistence-v3.js?v=3.2';
  script.dataset.pass50OfficialLinksPersistence = '3.2';
  document.head.appendChild(script);
})();

// Actualité automatique des fiches et Top 5 calculé à partir des captures métriques.
(function () {
  if (document.querySelector('script[data-pass50-content-intelligence]')) return;
  function loadContentIntelligence() {
    if (document.querySelector('script[data-pass50-content-intelligence]')) return;
    var script = document.createElement('script');
    script.src = './content-intelligence.js?v=1.1';
    script.dataset.pass50ContentIntelligence = '1.1';
    document.body.appendChild(script);
  }
  if (document.readyState === 'complete') loadContentIntelligence();
  else window.addEventListener('load', loadContentIntelligence, { once: true });
})();
