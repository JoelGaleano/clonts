<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

// Determinar la ruta base del proyecto para que recursos (CSS/JS/API) funcionen también bajo /secreto/123
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$baseHref  = $scriptDir . '/';

$initialSecretId = isset($_GET['secreto']) && is_numeric($_GET['secreto']) ? (int) $_GET['secreto'] : null;
$pageTitle       = 'ClonTS - Comunidad Social';
$pageDescription = 'Comunidad segura para desahogarte, compartir tus experiencias, interactuar y leer historias reales.';

if ($initialSecretId !== null && $initialSecretId > 0) {
    try {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare("SELECT id, title, content, category FROM secrets WHERE id = ? LIMIT 1");
        $stmt->execute([$initialSecretId]);
        $secretMeta = $stmt->fetch();

        if ($secretMeta) {
            $rawTitle = !empty($secretMeta['title'])
                ? $secretMeta['title']
                : 'Secreto #' . $secretMeta['id'] . ' (' . $secretMeta['category'] . ')';
            $rawDesc  = mb_substr(trim(preg_replace('/\s+/', ' ', (string) $secretMeta['content'])), 0, 160) . '...';

            $pageTitle       = htmlspecialchars($rawTitle . ' | ClonTS', ENT_QUOTES, 'UTF-8');
            $pageDescription = htmlspecialchars($rawDesc, ENT_QUOTES, 'UTF-8');
        }
    } catch (PDOException $e) {
        // Fallback silencioso a metadatos por defecto
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <base href="<?= htmlspecialchars($baseHref, ENT_QUOTES, 'UTF-8') ?>">
    <title><?= $pageTitle ?></title>
    <meta name="description" content="<?= $pageDescription ?>">

    <!-- Open Graph para previsualización al compartir enlaces de secretos -->
    <meta property="og:title" content="<?= $pageTitle ?>">
    <meta property="og:description" content="<?= $pageDescription ?>">
    <meta property="og:type" content="article">

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=<?= filemtime(__DIR__ . '/styles.css') ?>">
</head>
<body class="bg-gray-100 flex flex-col min-h-screen" data-initial-secret-id="<?= $initialSecretId ? (int) $initialSecretId : '' ?>">

    <!-- HEADER -->
    <header class="header-gradient text-white shadow-lg w-full sticky top-0 z-50">
        <div class="max-w-6xl mx-auto px-4 h-16 flex justify-between items-center">
            <div class="flex items-center space-x-3">
                <a href="./" onclick="return window.resetToHome(event)" class="text-2xl font-black brand-logo-header hover:text-blue-100 transition tracking-tight">ClonTS</a>
            </div>
            
            <nav class="hidden lg:flex lg:justify-center lg:flex-1 space-x-2">
                <a href="./" onclick="return window.resetToHome(event)" class="text-white font-bold bg-white/10 hover:bg-white/20 px-4 py-2 rounded-full backdrop-blur-sm shadow-sm transition">
                    <i class="fas fa-stream mr-2"></i>Feed Principal
                </a>
                <button onclick="window.openSecretModal()" class="text-blue-100 hover:text-white font-bold hover:bg-white/10 px-4 py-2 rounded-full transition">
                    <i class="fas fa-pen mr-2"></i>Publicar Secreto
                </button>
            </nav>

            <div class="flex items-center space-x-2 md:space-x-3" id="auth-buttons-container">
                <!-- Se llena dinámicamente con app.js -->
            </div>
        </div>
    </header>

    <!-- NAVEGACIÓN DE ORDENAMIENTO -->
    <div class="bg-white shadow-sm w-full z-10 overflow-x-auto no-scrollbar">
        <nav class="max-w-6xl mx-auto px-4 py-3 flex justify-start sm:justify-center space-x-4 sm:space-x-8 min-w-max">
            <button id="sort-Recientes" onclick="window.setSort('Recientes')" class="sort-btn text-blue-600 font-bold border-b-2 border-blue-600 pb-1 flex items-center space-x-2 text-sm sm:text-base transition-colors">
                <i class="fas fa-clock w-5 text-center"></i><span>Recientes</span>
            </button>
            <button id="sort-Top" onclick="window.setSort('Top')" class="sort-btn text-gray-600 hover:text-blue-600 font-medium border-b-2 border-transparent pb-1 flex items-center space-x-2 text-sm sm:text-base transition-colors">
                <i class="fas fa-fire w-5 text-center"></i><span>Top</span>
            </button>
            <button id="sort-Antiguos" onclick="window.setSort('Antiguos')" class="sort-btn text-gray-600 hover:text-blue-600 font-medium border-b-2 border-transparent pb-1 flex items-center space-x-2 text-sm sm:text-base transition-colors">
                <i class="fas fa-history w-5 text-center"></i><span>Antiguos</span>
            </button>
        </nav>
    </div>

    <!-- FILTROS -->
    <section id="filters-bar" class="bg-gray-50 border-b border-gray-200 w-full z-30">
        <div class="max-w-6xl mx-auto px-4 py-3">
            <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
                <div>
                    <label for="filter_gender" class="block text-xs font-medium text-gray-600 mb-1">Género</label>
                    <select id="filter_gender" class="w-full p-2 border border-gray-300 rounded-lg shadow-sm text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        <option value="Todos">Todos</option>
                        <option value="Hombre">Hombre</option>
                        <option value="Mujer">Mujer</option>
                        <option value="Otro">Otro</option>
                    </select>
                </div>
                <div>
                    <label for="filter_country" class="block text-xs font-medium text-gray-600 mb-1">País</label>
                    <select id="filter_country" class="w-full p-2 border border-gray-300 rounded-lg shadow-sm text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        <option value="Todos">Todos</option>
                        <option value="Argentina">Argentina</option>
                        <option value="México">México</option>
                        <option value="Colombia">Colombia</option>
                        <option value="España">España</option>
                        <option value="Perú">Perú</option>
                        <option value="Chile">Chile</option>
                        <option value="Uruguay">Uruguay</option>
                    </select>
                </div>
                <div>
                    <label for="filter_category" class="block text-xs font-medium text-gray-600 mb-1">Categoría</label>
                    <select id="filter_category" class="w-full p-2 border border-gray-300 rounded-lg shadow-sm text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        <option value="Todas">Todas</option>
                        <option value="Foro Libre">Foro Libre</option>
                        <option value="Amor y Pareja">Amor y Pareja</option>
                        <option value="Salud Mental">Salud Mental</option>
                        <option value="Humor">Humor</option>
                    </select>
                </div>
                <div>
                    <label for="filter_age_min" class="block text-xs font-medium text-gray-600 mb-1">Edad Mín.</label>
                    <input type="number" id="filter_age_min" min="13" max="99" class="w-full p-2 border border-gray-300 rounded-lg shadow-sm text-sm outline-none focus:ring-2 focus:ring-blue-500" placeholder="Ej: 18">
                </div>
                <div>
                    <label for="filter_age_max" class="block text-xs font-medium text-gray-600 mb-1">Edad Máx.</label>
                    <input type="number" id="filter_age_max" min="13" max="99" class="w-full p-2 border border-gray-300 rounded-lg shadow-sm text-sm outline-none focus:ring-2 focus:ring-blue-500" placeholder="Ej: 30">
                </div>
                <div class="col-span-2 md:col-span-1 flex items-end gap-2">
                    <button onclick="window.applyFilters()" class="flex-1 py-2 px-3 font-semibold text-white bg-blue-600 rounded-lg shadow-md hover:bg-blue-700 transition text-sm">
                        <i class="fas fa-filter mr-1"></i> Filtrar
                    </button>
                    <button onclick="window.clearFilters()" title="Limpiar filtros" class="py-2 px-3 font-semibold text-gray-600 bg-gray-200 rounded-lg hover:bg-gray-300 transition text-sm">
                        <i class="fas fa-undo"></i>
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- CONTENIDO PRINCIPAL Y SIDEBAR -->
    <div class="max-w-6xl mx-auto w-full flex-grow flex flex-col md:flex-row gap-6 p-4 md:py-8">
        <!-- FEED / VISTA DE SECRETO ÚNICO -->
        <main class="flex-1 min-w-0">
            <!-- Banner de vista individual cuando se abre la URL propia de un secreto -->
            <div id="single-secret-banner" class="hidden mb-4 bg-blue-50 border border-blue-200 rounded-xl p-4 flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                <div class="flex items-center gap-2 text-blue-800 font-bold text-sm">
                    <i class="fas fa-link text-blue-600"></i>
                    <span id="single-secret-banner-text">Viendo enlace directo de secreto</span>
                </div>
                <button onclick="window.resetToHome()" class="text-xs font-bold bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition shadow-sm">
                    <i class="fas fa-arrow-left mr-1"></i> Volver a todo el feed
                </button>
            </div>

            <div id="secrets-container">
                <!-- Se carga desde app.js -->
            </div>
        </main>

        <!-- SIDEBAR -->
        <aside class="w-full md:w-[320px] flex-shrink-0 space-y-6">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
                <h3 class="font-bold text-gray-800 text-lg mb-2"><i class="fas fa-info-circle text-blue-500 mr-2"></i>Sobre ClonTS</h3>
                <p class="text-sm text-gray-600 mb-4 leading-relaxed">Comunidad para desahogarte, compartir tus experiencias, personalizar tu estado y visitar perfiles de otros usuarios.</p>
                <button onclick="window.openSecretModal()" class="block w-full text-center bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-xl shadow-sm transition-colors">
                    <i class="fas fa-pen mr-2"></i>Escribir Historia
                </button>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
                <h3 class="font-bold text-gray-800 text-lg mb-3"><i class="fas fa-layer-group text-purple-500 mr-2"></i>Categorías</h3>
                <ul class="space-y-1.5 text-sm font-medium" id="sidebar-categories">
                    <li>
                        <button onclick="window.setCategory('Todas')" data-category="Todas" class="category-link active w-full flex items-center justify-between p-2.5 rounded-lg text-gray-600 hover:bg-gray-50 transition">
                            <span class="flex items-center gap-2.5"><i class="fas fa-globe w-4 text-blue-500"></i> Todas las categorías</span>
                        </button>
                    </li>
                    <li>
                        <button onclick="window.setCategory('Foro Libre')" data-category="Foro Libre" class="category-link w-full flex items-center justify-between p-2.5 rounded-lg text-gray-600 hover:bg-gray-50 transition">
                            <span class="flex items-center gap-2.5"><i class="fas fa-comments w-4 text-slate-500"></i> Foro Libre</span>
                        </button>
                    </li>
                    <li>
                        <button onclick="window.setCategory('Amor y Pareja')" data-category="Amor y Pareja" class="category-link w-full flex items-center justify-between p-2.5 rounded-lg text-gray-600 hover:bg-gray-50 transition">
                            <span class="flex items-center gap-2.5"><i class="fas fa-heart text-pink-600 w-4"></i> Amor y Pareja</span>
                        </button>
                    </li>
                    <li>
                        <button onclick="window.setCategory('Salud Mental')" data-category="Salud Mental" class="category-link w-full flex items-center justify-between p-2.5 rounded-lg text-gray-600 hover:bg-gray-50 transition">
                            <span class="flex items-center gap-2.5"><i class="fas fa-brain text-green-600 w-4"></i> Salud Mental</span>
                        </button>
                    </li>
                    <li>
                        <button onclick="window.setCategory('Humor')" data-category="Humor" class="category-link w-full flex items-center justify-between p-2.5 rounded-lg text-gray-600 hover:bg-gray-50 transition">
                            <span class="flex items-center gap-2.5"><i class="fas fa-laugh-beam text-yellow-600 w-4"></i> Humor</span>
                        </button>
                    </li>
                </ul>
            </div>
        </aside>
    </div>

    <!-- FOOTER -->
    <footer class="bg-white border-t border-gray-200 mt-auto w-full">
        <div class="max-w-6xl mx-auto px-4 py-6 flex flex-col sm:flex-row items-center justify-between gap-4 text-sm text-gray-600">
            <div class="flex items-center space-x-2">
                <span class="font-black text-blue-600 tracking-tight text-base">ClonTS</span>
                <span class="text-gray-300">|</span>
                <span>Desarrollado por <strong class="text-gray-800">Joel Galeano</strong></span>
            </div>
            <div class="flex items-center space-x-4">
                <a href="https://github.com/JoelGaleano" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 font-bold text-gray-700 hover:text-blue-600 bg-gray-100 hover:bg-blue-50 px-4 py-2 rounded-full transition">
                    <i class="fab fa-github text-lg"></i>
                    <span>github.com/JoelGaleano</span>
                </a>
            </div>
        </div>
    </footer>

    <!-- MODAL NUEVO SECRETO -->
    <div id="secret-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[90] hidden items-center justify-center p-4">
        <div class="bg-white rounded-3xl w-full max-w-2xl shadow-2xl flex flex-col overflow-hidden relative">
            <div class="px-8 py-5 border-b border-slate-100 flex justify-between items-center">
                <h3 class="font-extrabold text-xl text-slate-800"><i class="fa-solid fa-pen text-blue-500 mr-2"></i>Nueva Historia</h3>
                <button onclick="window.closeSecretModal()" class="text-slate-400 hover:text-slate-600 p-2"><i class="fa-solid fa-xmark text-xl"></i></button>
            </div>
            
            <div class="p-8 overflow-y-auto max-h-[70vh]">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Género</label>
                        <select id="modal-gender" class="w-full border-2 border-gray-100 rounded-xl px-3 py-2 text-sm focus:border-blue-500 outline-none">
                            <option value="Mujer">Mujer</option>
                            <option value="Hombre">Hombre</option>
                            <option value="Otro">Otro</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Edad</label>
                        <input type="number" id="modal-age" min="13" max="99" placeholder="Ej: 22" class="w-full border-2 border-gray-100 rounded-xl px-3 py-2 text-sm focus:border-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">País</label>
                        <select id="modal-country" class="w-full border-2 border-gray-100 rounded-xl px-3 py-2 text-sm focus:border-blue-500 outline-none">
                            <option value="Argentina">Argentina</option>
                            <option value="México">México</option>
                            <option value="Colombia">Colombia</option>
                            <option value="España">España</option>
                            <option value="Perú">Perú</option>
                            <option value="Chile">Chile</option>
                            <option value="Uruguay">Uruguay</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Categoría</label>
                        <select id="modal-category" class="w-full border-2 border-gray-100 rounded-xl px-3 py-2 text-sm focus:border-blue-500 outline-none">
                            <option value="Foro Libre">Foro Libre</option>
                            <option value="Amor y Pareja">Amor y Pareja</option>
                            <option value="Salud Mental">Salud Mental</option>
                            <option value="Humor">Humor</option>
                        </select>
                    </div>
                </div>
                <div class="mb-4">
                    <input type="text" id="modal-title" maxlength="150" placeholder="Título de tu historia (Opcional)" class="w-full border-2 border-gray-100 rounded-xl px-4 py-2 font-bold text-gray-800 focus:border-blue-500 outline-none">
                </div>
                <textarea id="secret-input" class="w-full border-2 border-gray-100 rounded-xl px-4 py-4 min-h-[160px] focus:border-blue-500 outline-none" placeholder="Escribe tu confesión o historia (mínimo 10 caracteres)..."></textarea>
            </div>
            
            <div class="px-8 py-5 bg-slate-50 border-t border-slate-100 flex justify-end gap-3">
                <button onclick="window.closeSecretModal()" class="px-6 py-2.5 text-gray-500 hover:text-gray-700 font-bold">Cancelar</button>
                <button id="submit-btn" onclick="window.submitSecret()" class="px-8 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-xl shadow-md transition">Publicar</button>
            </div>
        </div>
    </div>

    <!-- MODAL AUTH (REGISTRO Y LOGIN) -->
    <div id="auth-modal-overlay" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[95] hidden flex-col items-center justify-center p-4">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden relative p-8 max-h-[90vh] overflow-y-auto">
            <button type="button" onclick="window.closeAuthModals()" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 z-50 w-8 h-8 flex items-center justify-center rounded-full bg-slate-100 hover:bg-slate-200"><i class="fas fa-times"></i></button>
            <h2 class="text-2xl font-black text-center text-slate-900 mb-1" id="auth-title">Bienvenido</h2>
            <p class="text-xs text-center text-slate-500 mb-6" id="auth-subtitle">Interactúa con tu alias y personaliza tu perfil</p>
            
            <div class="flex border-b border-slate-200 mb-6">
                <button type="button" onclick="window.toggleAuthMode('login')" id="tab-login" class="flex-1 py-2 font-bold text-blue-600 border-b-2 border-blue-600">Iniciar Sesión</button>
                <button type="button" onclick="window.toggleAuthMode('register')" id="tab-register" class="flex-1 py-2 font-bold text-slate-400 border-b-2 border-transparent">Crear Cuenta</button>
            </div>

            <form onsubmit="window.submitAuth(event)" class="space-y-4" novalidate>
                <!-- Alias / Username (solo visible en Registro) -->
                <div id="register-username-group" class="hidden">
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Alias / Usuario</label>
                    <div class="relative">
                        <span class="absolute left-3.5 top-3 text-slate-400"><i class="fas fa-user"></i></span>
                        <input type="text" id="auth-username" placeholder="Ej: sombra_99" maxlength="25" class="w-full pl-10 pr-4 py-2.5 rounded-xl bg-slate-100 border-2 border-transparent focus:bg-white focus:border-blue-500 outline-none text-sm">
                    </div>
                    <span class="text-[11px] text-slate-400">3-25 caracteres (letras, números o guion bajo).</span>
                </div>

                <div>
                    <label id="auth-email-label" class="block text-xs font-bold text-slate-500 uppercase mb-1">Email o Alias</label>
                    <div class="relative">
                        <span class="absolute left-3.5 top-3 text-slate-400"><i class="fas fa-envelope"></i></span>
                        <input type="text" id="auth-email" placeholder="tucorreo@ejemplo.com" class="w-full pl-10 pr-4 py-2.5 rounded-xl bg-slate-100 border-2 border-transparent focus:bg-white focus:border-blue-500 outline-none text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Contraseña</label>
                    <div class="relative">
                        <span class="absolute left-3.5 top-3 text-slate-400"><i class="fas fa-lock"></i></span>
                        <input type="password" id="auth-password" oninput="window.updatePasswordStrength(this.value)" placeholder="Mínimo 8 caracteres (letras y números)" class="w-full pl-10 pr-10 py-2.5 rounded-xl bg-slate-100 border-2 border-transparent focus:bg-white focus:border-blue-500 outline-none text-sm">
                        <button type="button" onclick="window.togglePasswordVisibility('auth-password')" class="absolute right-3 top-2.5 text-slate-400 hover:text-slate-600 text-sm">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <!-- Medidor de fortaleza (solo visible en Registro) -->
                    <div id="password-strength-container" class="hidden mt-2">
                        <div class="w-full bg-slate-200 rounded-full h-1.5 overflow-hidden">
                            <div id="password-strength-bar" class="strength-bar w-0 bg-red-500"></div>
                        </div>
                        <p id="password-strength-text" class="text-[11px] text-slate-500 mt-1 font-medium">Ingresa al menos 8 caracteres con letras y números</p>
                    </div>
                </div>

                <!-- Confirmación y Perfil Demográfico (solo visible en Registro) -->
                <div id="register-extra-fields" class="hidden space-y-4 pt-1">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Confirmar Contraseña</label>
                        <div class="relative">
                            <span class="absolute left-3.5 top-3 text-slate-400"><i class="fas fa-check-double"></i></span>
                            <input type="password" id="auth-password-confirm" placeholder="Repite tu contraseña" class="w-full pl-10 pr-4 py-2.5 rounded-xl bg-slate-100 border-2 border-transparent focus:bg-white focus:border-blue-500 outline-none text-sm">
                        </div>
                    </div>

                    <div class="border-t border-slate-100 pt-3">
                        <p class="text-xs font-bold text-slate-500 uppercase mb-2">Datos básicos de tu perfil</p>
                        <div class="grid grid-cols-3 gap-2">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Género</label>
                                <select id="auth-gender" class="w-full px-2 py-2 rounded-xl bg-slate-100 text-xs font-medium outline-none focus:bg-white focus:ring-2 focus:ring-blue-500">
                                    <option value="Mujer">Mujer</option>
                                    <option value="Hombre">Hombre</option>
                                    <option value="Otro">Otro</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Edad</label>
                                <input type="number" id="auth-age" min="13" max="99" placeholder="Ej: 21" class="w-full px-2.5 py-2 rounded-xl bg-slate-100 text-xs font-medium outline-none focus:bg-white focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">País</label>
                                <select id="auth-country" class="w-full px-2 py-2 rounded-xl bg-slate-100 text-xs font-medium outline-none focus:bg-white focus:ring-2 focus:ring-blue-500">
                                    <option value="Argentina">Argentina</option>
                                    <option value="México">México</option>
                                    <option value="Colombia">Colombia</option>
                                    <option value="España">España</option>
                                    <option value="Perú">Perú</option>
                                    <option value="Chile">Chile</option>
                                    <option value="Uruguay">Uruguay</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="auth-error" class="hidden p-3 rounded-lg text-sm text-center font-medium bg-red-50 text-red-600 border border-red-100"></div>
                <button type="submit" id="auth-submit-btn" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-xl shadow-lg transition">Entrar</button>
            </form>
        </div>
    </div>

    <!-- MODAL COMENTARIOS (CON AUTOR VISIBLE Y RESPUESTAS) -->
    <div id="comments-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] hidden items-center justify-center p-4">
        <div class="bg-white rounded-3xl w-full max-w-2xl h-[85vh] shadow-2xl flex flex-col">
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white rounded-t-3xl z-10 shadow-sm">
                <h3 class="font-extrabold text-lg text-slate-800">
                    <i class="fa-solid fa-comments text-blue-500 mr-2"></i>Comentarios <span id="comments-modal-secret-id" class="text-sm font-bold text-slate-400 ml-1"></span>
                </h3>
                <button onclick="window.closeCommentsModal()" class="text-slate-400 hover:text-slate-600 p-2"><i class="fa-solid fa-xmark text-xl"></i></button>
            </div>
            
            <div class="flex-grow overflow-y-auto bg-slate-50 p-6 space-y-3" id="comments-list"></div>
            
            <div class="p-4 bg-white border-t border-slate-200 rounded-b-3xl">
                <!-- Banner de respuesta activa -->
                <div id="reply-banner" class="hidden mb-2 px-3 py-2 bg-blue-50 border border-blue-200 rounded-xl items-center justify-between text-xs text-blue-800 font-semibold">
                    <span><i class="fas fa-reply mr-1.5 text-blue-500"></i>Respondiendo a <strong id="reply-target-username">@usuario</strong></span>
                    <button type="button" onclick="window.cancelReply()" class="text-blue-500 hover:text-blue-700 font-bold ml-2">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                </div>
                <form onsubmit="window.postComment(event)" class="flex gap-2">
                    <input type="text" id="new-comment-input" maxlength="1000" placeholder="Escribe un comentario..." class="flex-grow border-2 border-slate-100 rounded-xl px-4 py-3 text-sm focus:border-blue-500 outline-none">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-xl font-bold transition"><i class="fa-solid fa-paper-plane"></i></button>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL PERFIL DE USUARIO -->
    <div id="profile-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[105] hidden items-center justify-center p-4">
        <div class="bg-white rounded-3xl w-full max-w-3xl h-[88vh] shadow-2xl flex flex-col overflow-hidden">
            <!-- Cabecera del Modal -->
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white z-10">
                <h3 class="font-extrabold text-lg text-slate-800 flex items-center gap-2">
                    <i class="fa-solid fa-id-card text-blue-600"></i>
                    <span>Perfil de Usuario</span>
                </h3>
                <button onclick="window.closeProfileModal()" class="text-slate-400 hover:text-slate-600 p-2">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <!-- Contenido scrolleable del Perfil -->
            <div class="flex-grow overflow-y-auto bg-slate-50 p-6 space-y-6" id="profile-modal-body">
                <!-- Se inyecta dinámicamente desde app.js -->
            </div>
        </div>
    </div>

    <!-- MODAL DE NOTIFICACIONES -->
    <div id="notifications-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[105] hidden items-center justify-center p-4">
        <div class="bg-white rounded-3xl w-full max-w-lg max-h-[82vh] shadow-2xl flex flex-col overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white">
                <h3 class="font-extrabold text-lg text-slate-800 flex items-center gap-2">
                    <i class="fa-solid fa-bell text-blue-500"></i>
                    <span>Tus Notificaciones</span>
                </h3>
                <div class="flex items-center gap-2">
                    <button onclick="window.markAllNotificationsRead()" class="text-xs font-bold text-blue-600 hover:text-blue-800 bg-blue-50 hover:bg-blue-100 px-3 py-1.5 rounded-lg transition">
                        <i class="fas fa-check-double mr-1"></i>Marcar leídas
                    </button>
                    <button onclick="window.closeNotificationsModal()" class="text-slate-400 hover:text-slate-600 p-2">
                        <i class="fa-solid fa-xmark text-xl"></i>
                    </button>
                </div>
            </div>
            <div class="flex-grow overflow-y-auto bg-slate-50 p-4 space-y-2.5" id="notifications-list">
                <!-- Se carga dinámicamente desde app.js -->
            </div>
        </div>
    </div>

    <!-- TOAST -->
    <div id="toast-notification" class="bg-gray-800 text-white py-3 px-5 rounded-lg shadow-xl fixed bottom-4 right-4 z-[120] transform translate-y-20 opacity-0 pointer-events-none transition-all duration-300">
        <i class="fas fa-info-circle mr-2 text-blue-400"></i><span id="toast-message">Notificación</span>
    </div>

    <script src="app.js?v=<?= filemtime(__DIR__ . '/app.js') ?>"></script>
</body>
</html>
