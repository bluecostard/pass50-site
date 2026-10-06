export type OnboardingSlideType = 'welcome' | 'ranking' | 'bet' | 'coules' | 'final';

export type OnboardingSlide = {
  step: string;
  stepHint: string;
  eyebrow: string;
  title: string;
  body: string;
  primary: string;
  type: OnboardingSlideType;
  note?: string;
  chips?: Array<{ icon: string; label: string; detail: string; tone: 'buzz' | 'up' | 'down' | 'top' }>;
};

/** Ordre maquette validée : Bienvenue → Classement → Parie → Coulés → À toi de jouer */
export const ONBOARDING_SLIDES: OnboardingSlide[] = [
  {
    step: 'Bienvenue',
    stepHint: 'Découvre l’app',
    eyebrow: 'PASS50 🇨🇮',
    title: 'L’actualité des influenceurs ivoiriens, autrement.',
    body: 'Classement actualisé toutes les 2h - 24h - 48h',
    primary: 'Commencer',
    type: 'welcome',
  },
  {
    step: 'Le classement',
    stepHint: 'Vois qui domine',
    eyebrow: 'LE CLASSEMENT',
    title: 'Le classement. Vois qui domine et grimpe dans le classement.',
    body: 'Le classement évolue naturellement selon l’actualité.',
    note: 'Aucun classement forcé.',
    primary: 'Suivant',
    type: 'ranking',
  },
  {
    step: 'Parie',
    stepHint: 'Sur l’actualité',
    eyebrow: 'PARIE SUR L’ACTUALITÉ',
    title: 'Parie sur l’actualité',
    body: 'Pronostique l’évolution des influenceurs et confronte ton intuition à celle de la communauté.',
    primary: 'Je tente ma chance',
    type: 'bet',
    chips: [
      { icon: '⚡', label: 'Parie', detail: 'Ça va faire le buzz', tone: 'buzz' },
      { icon: '↗', label: 'Progression', detail: 'Ça va monter', tone: 'up' },
      { icon: '↘', label: 'Chute', detail: 'Ça va chuter', tone: 'down' },
      { icon: '🏆', label: 'Top classement', detail: 'Ça va intégrer le top', tone: 'top' },
    ],
  },
  {
    step: 'Les coulés',
    stepHint: 'Qui mousse plus ? Ça va se savoir! 🌊',
    eyebrow: 'LES COULÉS',
    title: 'Les coulés. Qui mousse plus ? Ça va se savoir! 🌊',
    body: 'Retrouve les personnalités dont la dynamique est en baisse et découvre ce qui fait réagir la communauté.',
    primary: 'Voir qui est coulé',
    type: 'coules',
  },
  {
    step: 'À toi de jouer',
    stepHint: 'C’est parti !',
    eyebrow: 'À TOI DE JOUER',
    title: 'À toi de jouer. C’est parti !',
    body: 'Observe. Vote. Pronostique. Réagis.',
    note: 'Et reviens régulièrement : le classement peut changer à tout moment.',
    primary: 'Découvrir PASS50',
    type: 'final',
  },
];

export const ONBOARDING_STORAGE_KEY = 'pass50_onboarding_seen_v1';
