(() => {
  const ids = [
    'census-african-ryou',
    'census-samuella-kouassi',
    'census-nadiani',
    'census-investisseur-africain',
    'census-laura-ziehi',
    'census-aya-robert'
  ];
  const found = ids.map(id => db.profiles.find(profile => profile.id === id)).filter(Boolean);
  console.table(found.map(profile => ({
    id: profile.id,
    nom: profile.name,
    eligible: profile.eligible,
    classable: profile.classable,
    liensPublics: Object.keys(profile.links || {}).length
  })));
  console.log({
    total: db.profiles.length,
    sixPresents: found.length,
    resultatAttendu: '133 profils et 6/6 présents'
  });
})();