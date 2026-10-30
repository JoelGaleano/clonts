let currentUserId = null;
let currentUser = null;
let unreadNotificationsCount = 0;
let notificationPollTimer = null;

let authMode = 'login';
let currentOpenSecretId = null;
let currentReplyCommentId = null;
let currentReplyUsername = null;

let currentSort = 'Recientes';
let currentCategory = 'Todas';
let activeSecretId = null;

let activeProfileData = null;
let activeProfileTab = 'secrets';

const container = document.getElementById('secrets-container');
const authContainer = document.getElementById('auth-buttons-container');
const toast = document.getElementById('toast-notification');
const toastMessage = document.getElementById('toast-message');

/**
 * Construye la URL absoluta propia de un secreto para compartir.
 */
function getSecretShareUrl(secretId) {
    const baseUrl = window.location.origin + window.location.pathname.replace(/\/secreto\/\d+\/?$/, '/').replace(/\/index\.php$/, '/').replace(/\/secreto\.php$/, '/');
    const normalizedBase = baseUrl.endsWith('/') ? baseUrl : baseUrl + '/';
    return `${normalizedBase}?secreto=${encodeURIComponent(secretId)}`;
}

/**
 * Formatea una fecha SQL en tiempo relativo legible en español.
 */
function timeAgo(dateString) {
    if (!dateString) return 'Hace unos instantes';
    const date = new Date(dateString.replace(' ', 'T'));
    const seconds = Math.floor((Date.now() - date.getTime()) / 1000);
    if (isNaN(seconds) || seconds < 0) return 'Hace unos instantes';

    let interval = Math.floor(seconds / 86400);
    if (interval >= 1) return interval === 1 ? 'Hace 1 día' : `Hace ${interval} días`;
    interval = Math.floor(seconds / 3600);
    if (interval >= 1) return interval === 1 ? 'Hace 1 hora' : `Hace ${interval} horas`;
    interval = Math.floor(seconds / 60);
    if (interval >= 1) return interval === 1 ? 'Hace 1 minuto' : `Hace ${interval} minutos`;
    return 'Hace unos instantes';
}

window.showToast = function (message) {
    if (!toast || !toastMessage) return;
    toastMessage.textContent = message;
    toast.style.transform = 'translateY(0)';
    toast.classList.remove('opacity-0');
    setTimeout(() => {
        toast.style.transform = 'translateY(100px)';
        toast.classList.add('opacity-0');
    }, 3200);
};

/**
 * Inicializa la aplicación verificando sesión, notificaciones y estado de la URL.
 */
async function initApp() {
    const urlParams = new URLSearchParams(window.location.search);
    const paramSecret = urlParams.get('secreto') || urlParams.get('id') || document.body.dataset.initialSecretId;
    activeSecretId = paramSecret && !isNaN(Number(paramSecret)) ? Number(paramSecret) : null;

    try {
        const res = await fetch('api.php?action=auth_status', { credentials: 'same-origin' });
        const data = await res.json();
        currentUserId = data.logged_in ? data.user_id : null;
        currentUser = data.logged_in ? data.user : null;
        unreadNotificationsCount = data.logged_in ? Number(data.unread_notifications || 0) : 0;
    } catch (e) {
        currentUserId = null;
        currentUser = null;
        unreadNotificationsCount = 0;
    }

    setupNotificationPolling();
    updateAuthUI();
    updateSingleSecretBanner();
    await loadSecrets();
}

function setupNotificationPolling() {
    if (notificationPollTimer) {
        clearInterval(notificationPollTimer);
        notificationPollTimer = null;
    }
    if (currentUserId) {
        notificationPollTimer = setInterval(refreshUnreadNotifications, 25000);
    }
}

async function refreshUnreadNotifications() {
    if (!currentUserId) return;
    try {
        const res = await fetch('api.php?action=auth_status');
        const data = await res.json();
        if (data.logged_in) {
            unreadNotificationsCount = Number(data.unread_notifications || 0);
            updateAuthUI();
        }
    } catch (e) {
        // Ignorar errores silenciosos de red durante el polling
    }
}

function updateAuthUI() {
    if (!authContainer) return;

    if (currentUserId && currentUser) {
        const badgeHtml = unreadNotificationsCount > 0
            ? `<span class="absolute -top-1 -right-1 bg-red-500 text-white text-[10px] font-black min-w-[18px] h-[18px] px-1 rounded-full flex items-center justify-center shadow">${unreadNotificationsCount > 99 ? '99+' : unreadNotificationsCount}</span>`
            : '';

        authContainer.innerHTML = `
            <div class="flex items-center space-x-2 sm:space-x-2.5">
                <button onclick="window.openSecretModal()" class="lg:hidden text-xs font-bold bg-white/15 hover:bg-white/25 text-white px-3 py-1.5 rounded-full transition" title="Publicar secreto">
                    <i class="fas fa-pen"></i>
                </button>

                <button onclick="window.openNotificationsModal()" class="relative w-9 h-9 rounded-full bg-blue-800/60 hover:bg-blue-800 text-blue-100 hover:text-white flex items-center justify-center border border-blue-400/30 transition" title="Ver notificaciones">
                    <i class="fas fa-bell text-sm"></i>
                    ${badgeHtml}
                </button>

                <button onclick="window.openProfile(${currentUserId})" class="inline-flex items-center text-xs font-bold bg-blue-800/60 hover:bg-blue-800 text-blue-100 hover:text-white px-3 py-1.5 rounded-full border border-blue-400/30 transition" title="Ver mi perfil y estado">
                    <i class="fas fa-user-circle mr-1.5 text-sm"></i><span>@${currentUser.username}</span>
                </button>

                <button onclick="window.logout()" class="text-xs sm:text-sm font-bold bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded-full border border-red-500 shadow-sm transition" title="Cerrar sesión">
                    <i class="fas fa-sign-out-alt sm:mr-1"></i><span class="hidden sm:inline">Salir</span>
                </button>
            </div>
        `;
    } else {
        authContainer.innerHTML = `
            <div class="flex items-center space-x-2 sm:space-x-3">
                <button onclick="window.openAuthModal('login')" class="text-xs sm:text-sm font-bold text-blue-100 hover:text-white px-2 py-1 transition">Ingresar</button>
                <button onclick="window.openAuthModal('register')" class="text-xs sm:text-sm font-bold bg-blue-800 hover:bg-blue-900 text-white px-4 py-1.5 rounded-full border border-blue-600 shadow-sm transition">Regístrate</button>
            </div>
        `;
    }
}

function updateSingleSecretBanner() {
    const banner = document.getElementById('single-secret-banner');
    const bannerText = document.getElementById('single-secret-banner-text');
    const filtersBar = document.getElementById('filters-bar');

    if (!banner) return;

    if (activeSecretId) {
        banner.classList.remove('hidden');
        banner.classList.add('flex');
        if (bannerText) {
            bannerText.textContent = `Viendo el enlace directo del Secreto #${activeSecretId}`;
        }
        if (filtersBar) filtersBar.classList.add('hidden');
    } else {
        banner.classList.add('hidden');
        banner.classList.remove('flex');
        if (filtersBar) filtersBar.classList.remove('hidden');
    }
}

/**
 * Carga los secretos desde el backend aplicando filtros o buscando un secreto específico por ID.
 */
async function loadSecrets() {
    if (!container) return;

    const gender = document.getElementById('filter_gender')?.value || 'Todos';
    const country = document.getElementById('filter_country')?.value || 'Todos';
    const categorySelect = document.getElementById('filter_category')?.value || currentCategory || 'Todas';
    const minAge = document.getElementById('filter_age_min')?.value || '';
    const maxAge = document.getElementById('filter_age_max')?.value || '';

    const params = new URLSearchParams({
        action: 'get_secrets',
        gender,
        country,
        category: categorySelect,
        min_age: minAge,
        max_age: maxAge,
        sort: currentSort,
    });

    if (activeSecretId) {
        params.set('secret_id', String(activeSecretId));
    }

    container.innerHTML = `<div class="flex justify-center py-12"><i class="fa-solid fa-circle-notch fa-spin text-4xl text-blue-500"></i></div>`;

    try {
        const res = await fetch(`api.php?${params.toString()}`);
        const data = await res.json();
        renderSecrets(data.secrets || []);
    } catch (e) {
        container.innerHTML = `<div class="text-center py-12 bg-white rounded-2xl border border-red-200"><p class="text-red-500 font-bold">Error al cargar las historias.</p></div>`;
    }
}

window.applyFilters = function () {
    const categorySelect = document.getElementById('filter_category');
    if (categorySelect) {
        currentCategory = categorySelect.value;
        syncSidebarCategoryUI();
    }
    activeSecretId = null;
    updateSingleSecretBanner();
    loadSecrets();
};

window.clearFilters = function () {
    const genderEl = document.getElementById('filter_gender');
    const countryEl = document.getElementById('filter_country');
    const categoryEl = document.getElementById('filter_category');
    const minAgeEl = document.getElementById('filter_age_min');
    const maxAgeEl = document.getElementById('filter_age_max');

    if (genderEl) genderEl.value = 'Todos';
    if (countryEl) countryEl.value = 'Todos';
    if (categoryEl) categoryEl.value = 'Todas';
    if (minAgeEl) minAgeEl.value = '';
    if (maxAgeEl) maxAgeEl.value = '';

    currentCategory = 'Todas';
    activeSecretId = null;
    syncSidebarCategoryUI();
    updateSingleSecretBanner();
    loadSecrets();
};

window.setSort = function (sortType) {
    currentSort = sortType;
    document.querySelectorAll('.sort-btn').forEach(btn => {
        btn.className = 'sort-btn text-gray-600 hover:text-blue-600 font-medium border-b-2 border-transparent pb-1 flex items-center space-x-2 text-sm sm:text-base transition-colors';
    });
    const activeBtn = document.getElementById(`sort-${sortType}`);
    if (activeBtn) {
        activeBtn.className = 'sort-btn text-blue-600 font-bold border-b-2 border-blue-600 pb-1 flex items-center space-x-2 text-sm sm:text-base transition-colors';
    }
    loadSecrets();
};

window.setCategory = function (category) {
    currentCategory = category;
    const categorySelect = document.getElementById('filter_category');
    if (categorySelect) {
        categorySelect.value = category;
    }
    activeSecretId = null;
    updateUrlState(null);
    syncSidebarCategoryUI();
    updateSingleSecretBanner();
    loadSecrets();
};

function syncSidebarCategoryUI() {
    document.querySelectorAll('#sidebar-categories .category-link').forEach(btn => {
        if (btn.dataset.category === currentCategory) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
}

window.viewSecretById = function (secretId, event) {
    if (event) event.preventDefault();
    activeSecretId = Number(secretId);
    updateUrlState(activeSecretId);
    updateSingleSecretBanner();
    loadSecrets();
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

window.resetToHome = function (event) {
    if (event) event.preventDefault();
    activeSecretId = null;
    updateUrlState(null);
    updateSingleSecretBanner();
    loadSecrets();
    return false;
};

function updateUrlState(secretId) {
    const url = new URL(window.location.href);
    url.pathname = url.pathname.replace(/\/secreto\/\d+\/?$/, '/').replace(/\/secreto\.php$/, '/');
    if (secretId) {
        url.searchParams.set('secreto', String(secretId));
    } else {
        url.searchParams.delete('secreto');
        url.searchParams.delete('id');
    }
    window.history.pushState({ secretId }, '', url.toString());
}

window.addEventListener('popstate', () => {
    const params = new URLSearchParams(window.location.search);
    const sId = params.get('secreto') || params.get('id');
    activeSecretId = sId ? Number(sId) : null;
    updateSingleSecretBanner();
    loadSecrets();
});

window.copySecretLink = async function (secretId) {
    const shareUrl = getSecretShareUrl(secretId);
    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(shareUrl);
        } else {
            const tempInput = document.createElement('input');
            tempInput.value = shareUrl;
            document.body.appendChild(tempInput);
            tempInput.select();
            document.execCommand('copy');
            document.body.removeChild(tempInput);
        }
        window.showToast(`¡Enlace del secreto #${secretId} copiado al portapapeles!`);
    } catch (e) {
        window.showToast('No se pudo copiar el enlace automáticamente.');
    }
};

function renderSecrets(secrets) {
    if (!secrets || secrets.length === 0) {
        container.innerHTML = `
            <div class="text-center py-12 bg-white rounded-2xl border border-gray-200 p-6">
                <i class="fas fa-ghost text-4xl text-gray-300 mb-3"></i>
                <p class="text-gray-600 font-bold text-lg mb-1">No se encontraron secretos</p>
                <p class="text-gray-400 text-sm mb-4">Prueba cambiando los filtros o sé el primero en publicar una historia.</p>
                ${activeSecretId ? `<button onclick="window.resetToHome()" class="bg-blue-600 text-white text-sm font-bold px-4 py-2 rounded-lg">Ver todos los secretos</button>` : ''}
            </div>`;
        return;
    }

    container.innerHTML = secrets.map(secret => {
        const genderLower = (secret.gender || '').toLowerCase();
        const isFemale = genderLower === 'mujer' || genderLower === 'female';
        const isMale = genderLower === 'hombre' || genderLower === 'male';

        const headerBg = isFemale
            ? 'bg-pink-100 text-pink-800'
            : (isMale ? 'bg-blue-100 text-blue-800' : 'bg-purple-100 text-purple-800');
        const icon = isFemale ? 'fa-female' : (isMale ? 'fa-male' : 'fa-user');

        let catStyle = 'bg-gray-100 text-gray-600';
        let catIcon = 'fa-comments';
        if (secret.category === 'Amor y Pareja') { catStyle = 'bg-pink-100 text-pink-600'; catIcon = 'fa-heart'; }
        if (secret.category === 'Salud Mental') { catStyle = 'bg-green-100 text-green-600'; catIcon = 'fa-brain'; }
        if (secret.category === 'Humor') { catStyle = 'bg-yellow-100 text-yellow-600'; catIcon = 'fa-laugh-beam'; }

        const likeClass = secret.user_vote === 'like' ? 'text-green-600 scale-110' : 'text-gray-600';
        const dislikeClass = secret.user_vote === 'dislike' ? 'text-red-600 scale-110' : 'text-gray-600';
        const netVotes = Number(secret.likes) - Number(secret.dislikes);

        const shareUrl = getSecretShareUrl(secret.id);
        const shareText = `¡Mira el secreto #${secret.id} en ClonTS! ${shareUrl}`;
        const encodedShareText = encodeURIComponent(shareText);
        const encodedShareUrl = encodeURIComponent(shareUrl);

        const authorBadge = secret.user_id && secret.username
            ? `<button onclick="window.openProfile(${secret.user_id})" class="inline-flex items-center gap-1 font-extrabold underline decoration-dotted hover:opacity-80 transition" title="Ver perfil de @${secret.username}">
                   @${secret.username}
               </button>
               <span class="opacity-60">•</span>`
            : '';

        return `
        <article class="secret-card overflow-hidden flex flex-col bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
            <header class="${headerBg} px-5 py-3 flex justify-between items-center relative">
                <div class="flex items-center space-x-1.5 font-semibold flex-wrap z-10 text-sm">
                    <i class="fas ${icon} mr-0.5"></i>
                    ${authorBadge}
                    <span>${secret.age ? secret.age + ' años' : ''}</span>
                    <span class="opacity-75 font-normal">| ${secret.country}</span>
                </div>
                
                <div class=" hidden sm:flex absolute inset-0 items-center justify-center pointer-events-none">
                    <a href="?secreto=${secret.id}" onclick="window.viewSecretById(${secret.id}, event)" class="pointer-events-auto text-xs font-black opacity-40 hover:opacity-100 tracking-widest uppercase transition" title="Enlace permanente al secreto #${secret.id}">
                        #${secret.id}
                    </a>
                </div>

                <div class="flex items-center space-x-3 z-10">
                    <button onclick="window.toggleLike(${secret.id}, 'like')" class="${likeClass} hover:text-green-600 transition-transform" title="Me gusta">
                        <i class="fas fa-thumbs-up"></i>
                    </button>
                    <span class="font-bold text-sm">${netVotes}</span>
                    <button onclick="window.toggleLike(${secret.id}, 'dislike')" class="${dislikeClass} hover:text-red-600 transition-transform" title="No me gusta">
                        <i class="fas fa-thumbs-down"></i>
                    </button>
                </div>
            </header>
            
            <div class="py-6 px-5 flex flex-col">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-gray-400 tracking-wide">
                        <i class="far fa-clock mr-1"></i>${timeAgo(secret.created_at)}
                    </span>
                    <div class="flex items-center gap-3">
                        ${secret.user_id && secret.username ? `
                            <button onclick="window.openProfile(${secret.user_id})" class="text-xs font-bold text-slate-500 hover:text-blue-600 transition">
                                <i class="fas fa-user mr-1"></i>Ver perfil de @${secret.username}
                            </button>
                        ` : ''}
                        <a href="?secreto=${secret.id}" onclick="window.viewSecretById(${secret.id}, event)" class="text-xs font-bold text-blue-500 hover:text-blue-700 transition">
                            #${secret.id} <i class="fas fa-external-link-alt ml-0.5 text-[10px]"></i>
                        </a>
                    </div>
                </div>
                ${secret.title ? `<h3 class="text-xl font-black text-slate-800 mb-2 leading-tight"><a href="?secreto=${secret.id}" onclick="window.viewSecretById(${secret.id}, event)" class="hover:text-blue-600 transition">${secret.title}</a></h3>` : ''}
                <div class="text-gray-800 text-base mb-2 break-words prose prose-slate leading-relaxed whitespace-pre-wrap">${secret.content}</div>
            </div>
            
            <footer class="flex justify-between items-center mt-auto pt-3 pb-3 px-5 border-t border-slate-100 bg-slate-50/40">
                <div class="text-gray-500 text-sm flex items-center flex-wrap gap-2 md:gap-3">
                    <button onclick="window.openComments(${secret.id})" class="hover:text-blue-600 font-bold flex items-center gap-1.5 bg-white px-3 py-1 rounded-full border border-gray-200 shadow-sm transition">
                        <i class="fas fa-comment-alt text-blue-500"></i> <span>${secret.comments_count}</span>
                    </button>
                    
                    <button onclick="window.setCategory('${secret.category}')" class="text-[11px] font-bold px-2.5 py-1 rounded-full ${catStyle} hover:opacity-80 transition flex items-center gap-1.5 border border-black/5 shadow-sm">
                        <i class="fas ${catIcon}"></i> <span>${secret.category}</span>
                    </button>
                </div>

                <div class="flex items-center space-x-2 ml-auto pl-2">
                    <button onclick="window.copySecretLink(${secret.id})" class="text-xs font-bold text-slate-600 hover:text-blue-600 bg-white hover:bg-blue-50 border border-gray-200 px-2.5 py-1.5 rounded-lg shadow-sm transition flex items-center gap-1.5" title="Copiar URL del secreto">
                        <i class="fas fa-link"></i> <span class="hidden sm:inline">Copiar URL</span>
                    </button>
                    <a href="https://api.whatsapp.com/send?text=${encodedShareText}" target="_blank" rel="noopener noreferrer" class="w-8 h-8 rounded-lg bg-green-50 text-green-600 hover:bg-green-100 flex items-center justify-center transition" title="Compartir en WhatsApp">
                        <i class="fab fa-whatsapp text-lg"></i>
                    </a>
                    <a href="https://twitter.com/intent/tweet?text=${encodeURIComponent('¡Mira este secreto! ')}&url=${encodedShareUrl}" target="_blank" rel="noopener noreferrer" class="w-8 h-8 rounded-lg bg-sky-50 text-sky-500 hover:bg-sky-100 flex items-center justify-center transition" title="Compartir en X / Twitter">
                        <i class="fab fa-twitter text-lg"></i>
                    </a>
                </div>
            </footer>
        </article>`;
    }).join('');
}

window.toggleLike = async function (secretId, type) {
    if (!currentUserId) {
        window.showToast('Inicia sesión o regístrate para votar');
        return window.openAuthModal('login');
    }
    const res = await fetch('api.php?action=toggle_vote', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ secret_id: secretId, type }),
    });
    if (res.ok) {
        loadSecrets();
    } else {
        const err = await res.json();
        window.showToast(err.error || 'Error al registrar voto');
    }
};

window.submitSecret = async function () {
    if (!currentUserId) {
        window.showToast('Debes iniciar sesión para publicar');
        return window.openAuthModal('login');
    }

    const content = document.getElementById('secret-input').value.trim();
    if (content.length < 10) {
        window.showToast('Tu historia debe tener al menos 10 caracteres');
        return;
    }

    const submitBtn = document.getElementById('submit-btn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Publicando...';

    const payload = {
        title: document.getElementById('modal-title').value.trim(),
        content,
        gender: document.getElementById('modal-gender').value,
        age: document.getElementById('modal-age').value,
        country: document.getElementById('modal-country').value,
        category: document.getElementById('modal-category').value,
    };

    try {
        const res = await fetch('api.php?action=post_secret', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();

        if (res.ok && data.success) {
            window.closeSecretModal();
            window.showToast(`¡Secreto #${data.secret_id} publicado con éxito!`);
            activeSecretId = null;
            updateUrlState(null);
            updateSingleSecretBanner();
            loadSecrets();
        } else {
            window.showToast(data.error || 'Error al publicar el secreto');
        }
    } catch (e) {
        window.showToast('Error de red al publicar');
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = 'Publicar';
    }
};

// ==========================================
// COMENTARIOS (CON USUARIO VISIBLE Y RESPUESTAS)
// ==========================================

window.openComments = async function (secretId) {
    currentOpenSecretId = secretId;
    window.cancelReply();

    const modal = document.getElementById('comments-modal');
    const idBadge = document.getElementById('comments-modal-secret-id');
    if (idBadge) idBadge.textContent = `#${secretId}`;

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.style.overflow = 'hidden';

    const list = document.getElementById('comments-list');
    list.innerHTML = `<div class="flex justify-center py-8"><i class="fa-solid fa-circle-notch fa-spin text-2xl text-blue-500"></i></div>`;

    try {
        const res = await fetch(`api.php?action=get_comments&secret_id=${secretId}`);
        const data = await res.json();

        if (!data.comments || data.comments.length === 0) {
            list.innerHTML = `<div class="text-center py-10 text-slate-400 font-bold">Sé el primero en comentar en el secreto #${secretId}.</div>`;
        } else {
            list.innerHTML = data.comments.map(c => {
                const isReply = Boolean(c.parent_id);
                const replyClass = isReply ? 'ml-6 border-l-4 border-l-blue-400 bg-blue-50/30' : 'bg-white';
                const replyBadge = isReply && c.parent_username
                    ? `<div class="text-[11px] font-bold text-blue-600 mb-1 flex items-center gap-1">
                           <i class="fas fa-reply fa-flip-horizontal text-[10px]"></i>
                           <span>En respuesta a</span>
                           ${c.parent_user_id
                               ? `<button onclick="window.openProfile(${c.parent_user_id})" class="hover:underline">@${c.parent_username}</button>`
                               : `<span>@${c.parent_username}</span>`}
                       </div>`
                    : '';

                const userBtn = c.user_id
                    ? `<button onclick="window.openProfile(${c.user_id})" class="text-xs font-extrabold text-blue-700 hover:text-blue-900 hover:underline transition">@${c.username}</button>`
                    : `<span class="text-xs font-extrabold text-slate-700">@${c.username}</span>`;

                return `
                <div class="${replyClass} p-4 rounded-2xl shadow-sm border border-slate-100 flex gap-3 transition">
                    <button onclick="${c.user_id ? `window.openProfile(${c.user_id})` : ''}" class="w-9 h-9 rounded-full bg-blue-100 hover:bg-blue-200 flex items-center justify-center text-blue-600 flex-shrink-0 font-bold text-xs transition" title="Ver perfil de @${c.username}">
                        <i class="fa-solid fa-user"></i>
                    </button>
                    <div class="flex-1 min-w-0">
                        ${replyBadge}
                        <div class="flex items-center justify-between gap-2 mb-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                ${userBtn}
                                <span class="text-[10px] font-bold text-slate-400">• ${timeAgo(c.created_at)}</span>
                            </div>
                            <button onclick="window.setReplyTo(${c.id}, '${c.username.replace(/'/g, "\\'")}')" class="text-[11px] font-bold text-slate-400 hover:text-blue-600 flex items-center gap-1 transition">
                                <i class="fas fa-reply"></i> Responder
                            </button>
                        </div>
                        <p class="text-sm text-slate-700 font-medium break-words whitespace-pre-wrap">${c.content}</p>
                    </div>
                </div>
                `;
            }).join('');
            list.scrollTop = list.scrollHeight;
        }
    } catch (e) {
        list.innerHTML = `<div class="text-center py-8 text-red-500 font-bold">Error al cargar los comentarios.</div>`;
    }
};

window.setReplyTo = function (commentId, username) {
    if (!currentUserId) {
        window.closeCommentsModal();
        window.showToast('Inicia sesión para responder comentarios');
        return window.openAuthModal('login');
    }

    currentReplyCommentId = Number(commentId);
    currentReplyUsername = username;

    const banner = document.getElementById('reply-banner');
    const targetSpan = document.getElementById('reply-target-username');
    const input = document.getElementById('new-comment-input');

    if (targetSpan) targetSpan.textContent = `@${username}`;
    if (banner) {
        banner.classList.remove('hidden');
        banner.classList.add('flex');
    }
    if (input) {
        input.placeholder = `Respondiendo a @${username}...`;
        input.focus();
    }
};

window.cancelReply = function () {
    currentReplyCommentId = null;
    currentReplyUsername = null;
    const banner = document.getElementById('reply-banner');
    const input = document.getElementById('new-comment-input');

    if (banner) {
        banner.classList.add('hidden');
        banner.classList.remove('flex');
    }
    if (input) {
        input.placeholder = 'Escribe un comentario...';
    }
};

window.postComment = async function (e) {
    if (e) e.preventDefault();
    if (!currentUserId) {
        window.closeCommentsModal();
        window.showToast('Inicia sesión para dejar un comentario');
        return window.openAuthModal('login');
    }

    const input = document.getElementById('new-comment-input');
    const text = input.value.trim();
    if (!text || !currentOpenSecretId) return;

    const payload = {
        secret_id: currentOpenSecretId,
        content: text,
        parent_id: currentReplyCommentId,
    };

    input.value = '';
    window.cancelReply();

    try {
        const res = await fetch('api.php?action=post_comment', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();

        if (res.ok && data.success) {
            await window.openComments(currentOpenSecretId);
            loadSecrets();
        } else {
            window.showToast(data.error || 'No se pudo publicar el comentario');
        }
    } catch (err) {
        window.showToast('Error de red al comentar');
    }
};

window.closeCommentsModal = function () {
    const modal = document.getElementById('comments-modal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.style.overflow = 'auto';
    currentOpenSecretId = null;
    window.cancelReply();
};

// ==========================================
// PERFIL DE USUARIO (DATOS, ESTADO, SECRETOS Y COMENTARIOS)
// ==========================================

window.openProfile = async function (userId) {
    const targetId = userId || currentUserId;
    if (!targetId) {
        window.showToast('Inicia sesión para ver tu perfil');
        return window.openAuthModal('login');
    }

    const modal = document.getElementById('profile-modal');
    const body = document.getElementById('profile-modal-body');
    if (!modal || !body) return;

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.style.overflow = 'hidden';

    body.innerHTML = `<div class="flex justify-center py-16"><i class="fa-solid fa-circle-notch fa-spin text-4xl text-blue-500"></i></div>`;

    try {
        const res = await fetch(`api.php?action=get_profile&user_id=${encodeURIComponent(targetId)}`);
        const data = await res.json();

        if (!res.ok || !data.success) {
            body.innerHTML = `<div class="text-center py-12 text-red-500 font-bold">${data.error || 'No se pudo cargar el perfil.'}</div>`;
            return;
        }

        activeProfileData = data;
        activeProfileTab = 'secrets';
        renderProfileModalContent();
    } catch (e) {
        body.innerHTML = `<div class="text-center py-12 text-red-500 font-bold">Error de conexión al cargar el perfil.</div>`;
    }
};

function renderProfileModalContent() {
    const body = document.getElementById('profile-modal-body');
    if (!body || !activeProfileData) return;

    const { profile, secrets, comments } = activeProfileData;
    const isOwn = Boolean(profile.is_own_profile);

    const genderLower = (profile.gender || '').toLowerCase();
    const avatarBg = genderLower === 'mujer'
        ? 'bg-pink-100 text-pink-600'
        : (genderLower === 'hombre' ? 'bg-blue-100 text-blue-600' : 'bg-purple-100 text-purple-600');

    const editFormHtml = isOwn ? `
        <div id="profile-edit-form" class="hidden mt-4 pt-4 border-t border-slate-200 space-y-3">
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Tu Estado (máx. 160 caracteres)</label>
                <input type="text" id="edit-profile-status" maxlength="160" value="${profile.status_message}" class="w-full px-3.5 py-2 rounded-xl border-2 border-slate-200 focus:border-blue-500 outline-none text-sm font-medium">
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Género</label>
                    <select id="edit-profile-gender" class="w-full px-3 py-2 rounded-xl border-2 border-slate-200 focus:border-blue-500 outline-none text-sm">
                        <option value="Mujer" ${profile.gender === 'Mujer' ? 'selected' : ''}>Mujer</option>
                        <option value="Hombre" ${profile.gender === 'Hombre' ? 'selected' : ''}>Hombre</option>
                        <option value="Otro" ${profile.gender === 'Otro' ? 'selected' : ''}>Otro</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Edad</label>
                    <input type="number" id="edit-profile-age" min="13" max="99" value="${profile.age || ''}" placeholder="Ej: 22" class="w-full px-3 py-2 rounded-xl border-2 border-slate-200 focus:border-blue-500 outline-none text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">País</label>
                    <select id="edit-profile-country" class="w-full px-3 py-2 rounded-xl border-2 border-slate-200 focus:border-blue-500 outline-none text-sm">
                        ${['Argentina', 'México', 'Colombia', 'España', 'Perú', 'Chile', 'Uruguay', 'Otro'].map(c =>
                            `<option value="${c}" ${profile.country === c ? 'selected' : ''}>${c}</option>`
                        ).join('')}
                    </select>
                </div>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="window.toggleEditProfileForm()" class="px-4 py-2 text-xs font-bold text-slate-500 hover:text-slate-700">Cancelar</button>
                <button type="button" id="save-profile-btn" onclick="window.saveProfileChanges()" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl shadow transition">
                    <i class="fas fa-save mr-1"></i>Guardar Cambios
                </button>
            </div>
        </div>
    ` : '';

    body.innerHTML = `
        <!-- Tarjeta de Datos Básicos y Estado -->
        <div class="bg-white rounded-2xl p-6 shadow-sm border border-slate-200">
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="w-16 h-16 rounded-2xl ${avatarBg} flex items-center justify-center text-2xl font-black shadow-sm flex-shrink-0">
                        <i class="fas fa-user"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <h4 class="text-xl font-black text-slate-900">@${profile.username}</h4>
                            ${isOwn ? '<span class="text-[11px] font-bold bg-blue-100 text-blue-700 px-2.5 py-0.5 rounded-full">Tú</span>' : ''}
                        </div>
                        <div class="flex items-center gap-2 text-xs font-semibold text-slate-500 mt-1 flex-wrap">
                            <span><i class="fas fa-venus-mars mr-1 text-slate-400"></i>${profile.gender}</span>
                            <span>•</span>
                            <span>${profile.age ? profile.age + ' años' : 'Edad no especificada'}</span>
                            <span>•</span>
                            <span><i class="fas fa-globe-americas mr-1 text-slate-400"></i>${profile.country}</span>
                            <span>•</span>
                            <span>Miembro ${timeAgo(profile.created_at)}</span>
                        </div>
                    </div>
                </div>
                ${isOwn ? `
                    <button onclick="window.toggleEditProfileForm()" class="text-xs font-bold bg-slate-100 hover:bg-blue-50 text-slate-700 hover:text-blue-600 px-3.5 py-2 rounded-xl border border-slate-200 transition flex items-center gap-1.5">
                        <i class="fas fa-pen"></i> Cambiar estado / datos
                    </button>
                ` : ''}
            </div>

            <!-- Estado del usuario -->
            <div class="mt-4 bg-slate-50 border border-slate-200/80 rounded-xl p-3.5 flex items-start gap-2.5">
                <i class="fas fa-quote-left text-blue-400 text-sm mt-0.5"></i>
                <p class="text-sm text-slate-700 font-semibold italic break-words flex-1">${profile.status_message}</p>
            </div>

            ${editFormHtml}
        </div>

        <!-- Pestañas: Secretos publicados vs Comentarios -->
        <div class="flex border-b border-slate-200 bg-white rounded-t-2xl px-4 pt-2 shadow-sm">
            <button onclick="window.switchProfileTab('secrets')" id="prof-tab-secrets" class="flex-1 py-3 font-extrabold text-sm border-b-2 transition flex items-center justify-center gap-2 ${activeProfileTab === 'secrets' ? 'text-blue-600 border-blue-600' : 'text-slate-400 border-transparent hover:text-slate-600'}">
                <i class="fas fa-book-open"></i>
                <span>Secretos Publicados</span>
                <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">${secrets.length}</span>
            </button>
            <button onclick="window.switchProfileTab('comments')" id="prof-tab-comments" class="flex-1 py-3 font-extrabold text-sm border-b-2 transition flex items-center justify-center gap-2 ${activeProfileTab === 'comments' ? 'text-blue-600 border-blue-600' : 'text-slate-400 border-transparent hover:text-slate-600'}">
                <i class="fas fa-comments"></i>
                <span>Comentarios</span>
                <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">${comments.length}</span>
            </button>
        </div>

        <!-- Lista de la pestaña activa -->
        <div id="profile-tab-list" class="space-y-3">
            ${renderProfileTabItems()}
        </div>
    `;
}

function renderProfileTabItems() {
    if (!activeProfileData) return '';
    const { secrets, comments } = activeProfileData;

    if (activeProfileTab === 'secrets') {
        if (!secrets || secrets.length === 0) {
            return `<div class="bg-white rounded-2xl p-8 text-center text-slate-400 font-bold border border-slate-200">Este usuario aún no ha publicado secretos.</div>`;
        }
        return secrets.map(s => {
            const netVotes = Number(s.likes) - Number(s.dislikes);
            return `
            <div class="bg-white rounded-2xl p-4 shadow-sm border border-slate-200 hover:border-blue-300 transition">
                <div class="flex items-center justify-between text-xs text-slate-400 font-bold mb-1.5">
                    <span class="text-blue-600 bg-blue-50 px-2.5 py-0.5 rounded-full">#${s.id} • ${s.category}</span>
                    <span>${timeAgo(s.created_at)}</span>
                </div>
                ${s.title ? `<h5 class="font-extrabold text-slate-800 text-base mb-1">${s.title}</h5>` : ''}
                <p class="text-sm text-slate-700 whitespace-pre-wrap break-words mb-3">${s.content}</p>
                <div class="flex items-center justify-between pt-2 border-t border-slate-100 text-xs font-bold text-slate-500">
                    <div class="flex items-center gap-3">
                        <span><i class="fas fa-thumbs-up text-green-600 mr-1"></i>${netVotes} pts</span>
                        <span><i class="fas fa-comment text-blue-500 mr-1"></i>${s.comments_count} comentarios</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button onclick="window.closeProfileModal(); window.openComments(${s.id});" class="text-blue-600 hover:underline">
                            Ver comentarios
                        </button>
                        <span>•</span>
                        <button onclick="window.closeProfileModal(); window.viewSecretById(${s.id});" class="text-slate-600 hover:text-blue-600">
                            Ir al secreto <i class="fas fa-arrow-right ml-0.5"></i>
                        </button>
                    </div>
                </div>
            </div>`;
        }).join('');
    } else {
        if (!comments || comments.length === 0) {
            return `<div class="bg-white rounded-2xl p-8 text-center text-slate-400 font-bold border border-slate-200">Este usuario aún no ha realizado comentarios.</div>`;
        }
        return comments.map(c => `
            <div class="bg-white rounded-2xl p-4 shadow-sm border border-slate-200 hover:border-blue-300 transition">
                <div class="flex items-center justify-between text-xs font-bold text-slate-400 mb-1.5 flex-wrap gap-2">
                    <span class="text-slate-600">
                        Comentó en <strong class="text-blue-600">#${c.secret_id}</strong>
                        ${c.secret_title ? `(${c.secret_title})` : `(${c.secret_category})`}
                        ${c.parent_username ? ` <span class="text-blue-500">↪ respondiendo a @${c.parent_username}</span>` : ''}
                    </span>
                    <span>${timeAgo(c.created_at)}</span>
                </div>
                <p class="text-sm text-slate-700 font-medium whitespace-pre-wrap break-words mb-2">${c.content}</p>
                <div class="flex justify-end">
                    <button onclick="window.closeProfileModal(); window.openComments(${c.secret_id});" class="text-xs font-bold text-blue-600 hover:text-blue-800">
                        Abrir conversación del secreto #${c.secret_id} <i class="fas fa-external-link-alt ml-1"></i>
                    </button>
                </div>
            </div>
        `).join('');
    }
}

window.switchProfileTab = function (tab) {
    activeProfileTab = tab;
    renderProfileModalContent();
};

window.toggleEditProfileForm = function () {
    const form = document.getElementById('profile-edit-form');
    if (form) {
        form.classList.toggle('hidden');
    }
};

window.saveProfileChanges = async function () {
    const statusInput = document.getElementById('edit-profile-status');
    const genderInput = document.getElementById('edit-profile-gender');
    const ageInput = document.getElementById('edit-profile-age');
    const countryInput = document.getElementById('edit-profile-country');
    const btn = document.getElementById('save-profile-btn');

    if (!statusInput || !genderInput || !countryInput) return;

    const payload = {
        status_message: statusInput.value.trim(),
        gender: genderInput.value,
        age: ageInput ? ageInput.value : '',
        country: countryInput.value,
    };

    if (btn) btn.disabled = true;

    try {
        const res = await fetch('api.php?action=update_profile', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();

        if (res.ok && data.success) {
            currentUser = data.user;
            updateAuthUI();
            window.showToast('¡Perfil y estado actualizados!');
            await window.openProfile(currentUserId);
            loadSecrets();
        } else {
            window.showToast(data.error || 'Error al actualizar el perfil');
        }
    } catch (e) {
        window.showToast('Error de conexión al guardar cambios');
    } finally {
        if (btn) btn.disabled = false;
    }
};

window.closeProfileModal = function () {
    const modal = document.getElementById('profile-modal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.style.overflow = 'auto';
};

// ==========================================
// SISTEMA DE NOTIFICACIONES
// ==========================================

window.openNotificationsModal = async function () {
    if (!currentUserId) return;

    const modal = document.getElementById('notifications-modal');
    const list = document.getElementById('notifications-list');
    if (!modal || !list) return;

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.style.overflow = 'hidden';

    list.innerHTML = `<div class="flex justify-center py-10"><i class="fa-solid fa-circle-notch fa-spin text-2xl text-blue-500"></i></div>`;

    try {
        const res = await fetch('api.php?action=get_notifications');
        const data = await res.json();

        unreadNotificationsCount = Number(data.unread_count || 0);
        updateAuthUI();

        const notifs = data.notifications || [];
        if (notifs.length === 0) {
            list.innerHTML = `
                <div class="text-center py-12 text-slate-400">
                    <i class="fas fa-bell-slash text-3xl mb-2 opacity-50"></i>
                    <p class="font-bold text-sm">No tienes notificaciones todavía</p>
                    <p class="text-xs mt-1">Te avisaremos cuando alguien comente tus secretos o responda tus comentarios.</p>
                </div>`;
            return;
        }

        list.innerHTML = notifs.map(n => {
            const isReply = n.type === 'reply_comment';
            const icon = isReply ? 'fa-reply text-purple-600 bg-purple-100' : 'fa-comment-dots text-blue-600 bg-blue-100';
            const actionText = isReply
                ? `respondió a tu comentario en el <strong>Secreto #${n.secret_id}</strong>`
                : `comentó en tu <strong>Secreto #${n.secret_id}</strong>`;
            const unreadBg = n.is_read ? 'bg-white border-slate-100' : 'bg-blue-50/70 border-blue-200';

            return `
            <div class="${unreadBg} p-3.5 rounded-2xl border shadow-sm flex items-start gap-3 transition hover:border-blue-300">
                <div class="w-9 h-9 rounded-xl ${icon} flex items-center justify-center flex-shrink-0 mt-0.5">
                    <i class="fas ${isReply ? 'fa-reply' : 'fa-comment-dots'} text-sm"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-xs text-slate-700 leading-snug">
                        <button onclick="window.closeNotificationsModal(); window.openProfile(${n.actor_id});" class="font-extrabold text-blue-700 hover:underline">@${n.actor_username}</button>
                        <span class="cursor-pointer" onclick="window.openNotificationTarget(${n.id}, ${n.secret_id})"> ${actionText}</span>
                    </div>
                    <p onclick="window.openNotificationTarget(${n.id}, ${n.secret_id})" class="text-xs text-slate-500 italic mt-1 truncate cursor-pointer">"${n.comment_snippet}"</p>
                    <div class="flex items-center justify-between mt-1.5">
                        <span class="text-[10px] font-bold text-slate-400">${timeAgo(n.created_at)}</span>
                        <button onclick="window.openNotificationTarget(${n.id}, ${n.secret_id})" class="text-[11px] font-bold text-blue-600 hover:underline">
                            Ver conversación <i class="fas fa-arrow-right ml-0.5"></i>
                        </button>
                    </div>
                </div>
                ${!n.is_read ? `<span class="w-2.5 h-2.5 rounded-full bg-blue-600 flex-shrink-0 mt-2" title="No leída"></span>` : ''}
            </div>`;
        }).join('');
    } catch (e) {
        list.innerHTML = `<div class="text-center py-8 text-red-500 font-bold text-sm">Error al cargar notificaciones.</div>`;
    }
};

window.openNotificationTarget = async function (notificationId, secretId) {
    try {
        await fetch('api.php?action=mark_notifications_read', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ notification_id: notificationId }),
        });
        await refreshUnreadNotifications();
    } catch (e) {
        // Continuar incluso si falla el marcado
    }
    window.closeNotificationsModal();
    window.openComments(secretId);
};

window.markAllNotificationsRead = async function () {
    try {
        await fetch('api.php?action=mark_notifications_read', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({}),
        });
        unreadNotificationsCount = 0;
        updateAuthUI();
        await window.openNotificationsModal();
        window.showToast('Notificaciones marcadas como leídas');
    } catch (e) {
        window.showToast('Error al actualizar notificaciones');
    }
};

window.closeNotificationsModal = function () {
    const modal = document.getElementById('notifications-modal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.style.overflow = 'auto';
};

// ==========================================
// MODAL NUEVO SECRETO
// ==========================================

window.openSecretModal = function () {
    if (!currentUserId) {
        window.showToast('Debes iniciar sesión para publicar una historia');
        return window.openAuthModal('login');
    }

    if (currentUser) {
        const genderSelect = document.getElementById('modal-gender');
        const ageInput = document.getElementById('modal-age');
        const countrySelect = document.getElementById('modal-country');

        if (genderSelect && currentUser.gender) genderSelect.value = currentUser.gender;
        if (ageInput && currentUser.age) ageInput.value = currentUser.age;
        if (countrySelect && currentUser.country) countrySelect.value = currentUser.country;
    }

    const modal = document.getElementById('secret-modal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.style.overflow = 'hidden';
    document.getElementById('secret-input').value = '';
    document.getElementById('modal-title').value = '';
};

window.closeSecretModal = function () {
    const modal = document.getElementById('secret-modal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.style.overflow = 'auto';
};

// ==========================================
// SISTEMA DE AUTENTICACIÓN Y REGISTRO
// ==========================================

window.openAuthModal = function (mode = 'login') {
    const modal = document.getElementById('auth-modal-overlay');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.style.overflow = 'hidden';
    window.toggleAuthMode(mode);
};

window.closeAuthModals = function () {
    const modal = document.getElementById('auth-modal-overlay');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.style.overflow = 'auto';
    document.getElementById('auth-error').classList.add('hidden');
};

window.togglePasswordVisibility = function (inputId) {
    const input = document.getElementById(inputId);
    if (!input) return;
    input.type = input.type === 'password' ? 'text' : 'password';
};

window.updatePasswordStrength = function (password) {
    if (authMode !== 'register') return;
    const bar = document.getElementById('password-strength-bar');
    const text = document.getElementById('password-strength-text');
    if (!bar || !text) return;

    let score = 0;
    if (password.length >= 8) score += 1;
    if (/[A-Za-z]/.test(password) && /\d/.test(password)) score += 1;
    if (/[A-Z]/.test(password) && /[a-z]/.test(password)) score += 1;
    if (/[^A-Za-z0-9]/.test(password) || password.length >= 12) score += 1;

    const levels = [
        { width: '15%', color: 'bg-red-500', label: 'Muy débil (mínimo 8 caracteres, letras y números)' },
        { width: '40%', color: 'bg-orange-500', label: 'Débil (agrega letras y números)' },
        { width: '70%', color: 'bg-yellow-500', label: 'Buena (cumple los requisitos)' },
        { width: '90%', color: 'bg-green-500', label: 'Fuerte' },
        { width: '100%', color: 'bg-emerald-600', label: 'Excelente nivel de seguridad' },
    ];

    const current = password.length === 0 ? { width: '0%', color: 'bg-red-500', label: 'Ingresa al menos 8 caracteres con letras y números' } : levels[score];
    bar.className = `strength-bar ${current.color}`;
    bar.style.width = current.width;
    text.textContent = current.label;
};

window.toggleAuthMode = function (mode) {
    authMode = mode;
    const btnLogin = document.getElementById('tab-login');
    const btnRegister = document.getElementById('tab-register');
    const usernameGroup = document.getElementById('register-username-group');
    const extraFields = document.getElementById('register-extra-fields');
    const strengthContainer = document.getElementById('password-strength-container');
    const emailLabel = document.getElementById('auth-email-label');
    const emailInput = document.getElementById('auth-email');
    const errorDiv = document.getElementById('auth-error');

    if (errorDiv) errorDiv.classList.add('hidden');

    if (mode === 'login') {
        document.getElementById('auth-title').innerText = 'Bienvenido de nuevo';
        document.getElementById('auth-submit-btn').innerText = 'Iniciar Sesión';
        btnLogin.className = 'flex-1 py-2 font-bold text-blue-600 border-b-2 border-blue-600';
        btnRegister.className = 'flex-1 py-2 font-bold text-slate-400 border-b-2 border-transparent hover:text-slate-600';

        usernameGroup?.classList.add('hidden');
        extraFields?.classList.add('hidden');
        strengthContainer?.classList.add('hidden');
        if (emailLabel) emailLabel.innerText = 'Email o Alias';
        if (emailInput) emailInput.placeholder = 'Tu correo o alias';
    } else {
        document.getElementById('auth-title').innerText = 'Crea tu cuenta';
        document.getElementById('auth-submit-btn').innerText = 'Crear Cuenta';
        btnRegister.className = 'flex-1 py-2 font-bold text-blue-600 border-b-2 border-blue-600';
        btnLogin.className = 'flex-1 py-2 font-bold text-slate-400 border-b-2 border-transparent hover:text-slate-600';

        usernameGroup?.classList.remove('hidden');
        extraFields?.classList.remove('hidden');
        strengthContainer?.classList.remove('hidden');
        if (emailLabel) emailLabel.innerText = 'Correo Electrónico';
        if (emailInput) emailInput.placeholder = 'tucorreo@ejemplo.com';
    }
};

window.submitAuth = async function (e) {
    e.preventDefault();
    const email = document.getElementById('auth-email').value.trim();
    const password = document.getElementById('auth-password').value;
    const errorDiv = document.getElementById('auth-error');
    const btn = document.getElementById('auth-submit-btn');

    const showAuthError = (msg) => {
        errorDiv.innerText = msg;
        errorDiv.classList.remove('hidden');
    };

    if (!email || !password) {
        return showAuthError('Por favor completa los campos obligatorios.');
    }

    const payload = { email, password };

    if (authMode === 'register') {
        const username = document.getElementById('auth-username').value.trim();
        const passwordConfirm = document.getElementById('auth-password-confirm').value;
        const gender = document.getElementById('auth-gender').value;
        const age = document.getElementById('auth-age').value;
        const country = document.getElementById('auth-country').value;

        if (!/^[a-zA-Z0-9_]{3,25}$/.test(username)) {
            return showAuthError('El alias debe tener entre 3 y 25 caracteres (letras, números o guion bajo).');
        }
        if (password.length < 8 || !/[A-Za-z]/.test(password) || !/\d/.test(password)) {
            return showAuthError('La contraseña debe tener mínimo 8 caracteres e incluir letras y números.');
        }
        if (password !== passwordConfirm) {
            return showAuthError('Las contraseñas no coinciden.');
        }

        payload.username = username;
        payload.password_confirm = passwordConfirm;
        payload.gender = gender;
        payload.age = age;
        payload.country = country;
    }

    btn.disabled = true;
    errorDiv.classList.add('hidden');

    try {
        const action = authMode === 'login' ? 'login' : 'register';
        const res = await fetch(`api.php?action=${action}`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();

        if (res.ok && data.success) {
            if (data.user) {
                currentUser = data.user;
                currentUserId = Number(data.user.id);
                updateAuthUI();
            }
            document.getElementById('auth-password').value = '';
            const confirmInput = document.getElementById('auth-password-confirm');
            if (confirmInput) confirmInput.value = '';
            window.showToast(authMode === 'register' ? '¡Cuenta creada exitosamente!' : '¡Bienvenido de nuevo!');
            window.closeAuthModals();
            await initApp();
        } else {
            showAuthError(data.error || 'Error al procesar la solicitud.');
        }
    } catch (err) {
        showAuthError('Error de conexión con el servidor.');
    } finally {
        btn.disabled = false;
    }
};

window.logout = async function () {
    await fetch('api.php?action=logout');
    window.showToast('Sesión cerrada correctamente');
    currentUserId = null;
    currentUser = null;
    unreadNotificationsCount = 0;
    setupNotificationPolling();
    updateAuthUI();
    loadSecrets();
};

document.addEventListener('DOMContentLoaded', initApp);
