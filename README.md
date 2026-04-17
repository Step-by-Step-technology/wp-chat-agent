# Assistant IA — Plugin WordPress

Plugin WordPress open-source qui ajoute un **chat assistant conversationnel** propulsé par OpenAI sur n'importe quel site. Tout est configurable depuis l'admin : clés API, modèles, couleurs, thèmes, persistance, filtres… Aucune donnée personnelle ni clé API n'est stockée en dur dans le code.

[![PHP](https://img.shields.io/badge/PHP-8.0+-blue.svg)]()
[![WordPress](https://img.shields.io/badge/WordPress-5.0+-green.svg)]()
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-orange.svg)](https://www.gnu.org/licenses/gpl-2.0)

---

## ✨ Fonctionnalités

- 💬 **Chat flottant** en bas à droite, responsive, personnalisable
- 🧠 **Intégration OpenAI** : GPT-5.4 Nano, GPT-5 Nano, GPT-4.1 Nano (et compatibilité modèles plus anciens)
- 🔍 **Recherche web optionnelle** via Brave Search API (déclenchée par mots-clés configurables)
- 🧠 **RAG (Retrieval-Augmented Generation)** : indexation vectorielle du contenu WP (posts, pages, CPT), l'IA répond avec précision en s'appuyant sur **votre** contenu
- 💾 **Persistance de la conversation** entre pages : `session` (par onglet), `local` (multi-sessions avec TTL) ou désactivée
- 🎯 **Filtrage par thèmes** : restreint les réponses à une liste de sujets autorisés (deuxième appel OpenAI pour valider la pertinence)
- 📞 **Boutons de contact** (téléphone + page contact) affichés selon la longueur de réponse
- 📝 **Journal des conversations** en base de données, consultable dans l'admin, avec rétention configurable
- 🔐 **Rate limiting** par IP (anti-abus)
- 🧪 **Bouton de test OpenAI** dans l'admin pour vérifier la config en un clic
- 🌐 **Admin 100% en français**, config-driven (schéma unique, 7 sections)
- 🛡️ **XSS-safe**, nonces partout, sanitize-on-input, escape-on-output
- 🎨 **Couleur primaire personnalisable** avec color picker WP

---

## 📦 Installation

### Via FTP / téléchargement manuel

1. Télécharge le dossier du plugin ou clone le dépôt :
   ```bash
   git clone https://github.com/TON-USER/assistant-ia-wp.git wp-ai-cgc-assistant
   ```
2. Place le dossier dans `wp-content/plugins/` de ton installation WordPress.
3. Active le plugin depuis **Extensions** dans le tableau de bord WP.
4. Va dans **Assistant IA → Réglages** pour configurer ta clé API OpenAI.

### Prérequis

- WordPress ≥ 5.0
- PHP ≥ 8.0
- Une clé API OpenAI ([obtenir](https://platform.openai.com/api-keys))
- (optionnel) Une clé Brave Search ([obtenir](https://brave.com/search/api/))

---

## ⚙️ Configuration

Toute la config est dans **Assistant IA → Réglages**, organisée en **onglets** :

| Onglet | Contenu |
|---|---|
| **Clés API et modèles** | Clé OpenAI, clé Brave, modèle principal, longueur max. des réponses |
| **Apparence et identité** | Nom de l'assistant, message de bienvenue, placeholder, couleur principale, crédit auteur |
| **Boutons de contact** | Numéro tél., URL page contact, seuil d'affichage |
| **Persistance de la conversation** | Mode session/local/désactivée, durée, taille de l'historique |
| **Filtrage par thèmes** | Activation, liste des thèmes, message hors-périmètre |
| **RAG — Connaissance du site** | Activation, modèle d'embedding, types de contenu, taille des chunks, nb chunks injectés |
| **Mots-clés de recherche web** | Liste des déclencheurs |
| **Sécurité et débogage** | Rate limit, mode debug, journalisation, rétention |

Un bouton **« Tester maintenant »** sur l'onglet *Clés API* valide la connexion OpenAI (clé + modèle) sans passer par le chat.

Le menu **Assistant IA** contient aussi :
- **Journal** — consultation des conversations (si journalisation activée)
- **Indexation RAG** — statut de l'index, bouton d'indexation, bouton de vidage

## 🧠 RAG — Mode d'emploi

Le RAG permet à l'assistant de répondre avec **votre contenu** (articles, pages, custom post types), au lieu de rester cantonné à sa connaissance générale.

### Workflow

1. **Configurer la clé API OpenAI** (onglet *Clés API*)
2. **Onglet RAG** : activer, choisir le modèle d'embedding, définir les types de contenu à indexer
3. **Aller sur Assistant IA → Indexation RAG → Indexer tout le site**
4. Une fois indexé, chaque modification d'article déclenche une ré-indexation automatique (hook `save_post`)

### Comment ça marche

À chaque question utilisateur :
1. La question est vectorisée (embedding OpenAI)
2. Les N chunks les plus proches dans l'index sont récupérés (similarité cosinus)
3. Ils sont injectés dans le prompt de l'IA comme contexte
4. L'IA répond en s'appuyant sur ces extraits

### Coûts

- **Indexation** : ~0,02 $ pour 100 pages avec `text-embedding-3-small`
- **Par question** : 1 embedding de question (~0,00002 $) + le chat (tarif normal du modèle choisi)

---

## 🧱 Architecture

```
wp-ai-cgc-assistant/
├── wp-ai-cgc-assistant.php              # Point d'entrée WordPress (plugin header)
├── includes/
│   ├── class-ai-assistant.php           # Bootstrap (Singleton) + garde-fou fatals AJAX
│   ├── class-ai-assistant-settings.php  # Réglages (config-driven, FR) + page Journal
│   ├── class-ai-assistant-ajax.php      # Endpoint AJAX + appels OpenAI (compat max_completion_tokens)
│   ├── class-ai-assistant-assets.php    # Enqueue CSS/JS + CSS dynamique
│   ├── class-ai-assistant-search.php    # Intégration Brave Search
│   ├── class-ai-assistant-logger.php    # Table DB + rétention des logs
│   └── class-ai-assistant-rag.php       # Indexation vectorielle + recherche cosinus
├── assets/
│   ├── css/chat.css
│   ├── js/chat.js                       # Widget + persistance (session/localStorage) + XSS-safe
│   └── generative.png
├── README.md
└── LICENSE
```

---

## 🔒 Sécurité

Le plugin respecte les meilleures pratiques WordPress :

- **Nonces** (`wp_verify_nonce`) sur toutes les requêtes AJAX (front + admin)
- **Capability check** (`current_user_can('manage_options')`) sur toutes les actions admin
- **Sanitize** sur toutes les entrées (`sanitize_text_field`, `sanitize_textarea_field`, `absint`, `esc_url_raw`, `sanitize_hex_color`)
- **Escape** sur toutes les sorties (`esc_html`, `esc_attr`, `esc_url`, `esc_textarea`)
- **Requêtes préparées** (`$wpdb->prepare`) pour toutes les requêtes DB
- **Protection XSS côté front** : le contenu utilisateur est échappé via `$('<div>').text().html()` avant rendu
- **Rate limiting** par IP pour limiter l'abus de la clé OpenAI
- **Pas d'`eval`, pas d'`unserialize`**, pas de `file_put_contents` non contrôlé
- **Clé API** : stockée chiffrée par WordPress dans `wp_options` (sérialisation native), jamais loggée ni envoyée côté client
- **Garde-fou fatal** : un shutdown handler intercepte tout fatal PHP pendant l'AJAX et renvoie un JSON propre au lieu d'une page HTML d'erreur

Aucune donnée personnelle (URL, email, téléphone, nom de site) n'est hardcodée dans le code — tout est configuré par l'administrateur.

---

## 📝 RGPD & journalisation

Si tu actives la journalisation (`Réglages → Sécurité et débogage → Journaliser les conversations`), **tu dois** :

1. Informer tes utilisateurs via ta politique de confidentialité
2. Définir une durée de rétention raisonnable (champ prévu)
3. Répondre aux demandes d'effacement RGPD (bouton « Vider le journal » + filtrage par IP fourni)

Données stockées par échange :
- Horodatage (UTC)
- IP source
- ID utilisateur WP (si connecté) ou NULL
- Question posée
- Réponse de l'IA

---

## 🚀 Utilisation

Une fois le plugin actif et configuré :

1. Le widget apparaît automatiquement sur toutes les pages du front
2. Les visiteurs cliquent sur la bulle pour ouvrir le chat
3. La conversation est conservée entre les pages (selon le mode choisi)
4. L'historique est consultable dans **Assistant IA → Journal**

---

## 🤝 Contribution

Issues et pull requests bienvenues. Workflow simple :

1. Fork le repo
2. Crée une branche (`git checkout -b feat/ma-fonctionnalite`)
3. Commit (`git commit -am 'feat: ajout de X'`)
4. Push (`git push origin feat/ma-fonctionnalite`)
5. Ouvre une Pull Request

---

## 📜 Licence

[GPL v2 ou ultérieure](https://www.gnu.org/licenses/gpl-2.0) — cohérent avec l'écosystème WordPress.

---

## 🙏 Crédits

Développement : [Step by Step](https://step-by-step.technology)

Modèles IA : [OpenAI](https://openai.com/)
Recherche web : [Brave Search API](https://brave.com/search/api/)

Le crédit dans le footer du chat est désactivable depuis les réglages.

---

## 📦 Changelog

### 2.8.0
- Ajout du **RAG** : indexation vectorielle du contenu du site + recherche par similarité cosinus
- Nouvelle page admin « Indexation RAG » avec barre de progression
- Ré-indexation automatique sur `save_post`
- **Admin en onglets** : 8 sections regroupées, navigation plus claire
- Nouveau réglage : modèle d'embedding, types de contenu, taille des chunks, nb chunks injectés

### 2.7.0
- Simplification du filtrage par thèmes : injection dans le system prompt au lieu d'un second appel OpenAI
- Plus fiable sur les salutations / questions courtes, plus rapide, moins coûteux
- Suppression du réglage « Modèle de filtrage » (devenu inutile)

### 2.6.0
- Suppression de toutes les références hardcodées (CG Consulting, cgconsulting.corsica, numéros de tél.)
- Suppression du fichier mort `content-fetcher.php`
- Ajout option « Afficher le crédit auteur » (désactivable)
- README public pour publication GitHub

### 2.5.0
- Journal des conversations en base de données
- Page admin dédiée avec pagination, recherche, filtrage par IP
- Bouton « Vider le journal »

### 2.4.x
- Bouton de test OpenAI dans l'admin
- Bouton « Réinitialiser le compteur anti-abus »
- Fix : support `max_completion_tokens` pour les modèles GPT-5 / GPT-4.1 / o1 / o3

### 2.3.x
- Garde-fou fatals AJAX (output buffering + shutdown handler)
- Messages d'erreur enrichis côté client

### 2.2.x
- Enregistrement direct des hooks AJAX dans le fichier principal
- Guard `is_real_admin_request()` pour éviter les effets de bord en AJAX

### 2.1.x
- Correctifs de robustesse, gestion des erreurs propre

### 2.0.0
- Refactor complet : admin entièrement en français, config-driven
- Persistance de la conversation (session/local storage)
- Correctif XSS
- Modèle OpenAI réellement utilisé (était hardcodé)
- Debug logs conditionnés

### 1.5.0
- Version initiale
