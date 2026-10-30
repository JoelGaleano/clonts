# ClonTS — Plataforma Social de Historias y Confesiones

**ClonTS** es una aplicación web social inspirada en *TuSecreto*, desarrollada con **PHP 8+ puro**, **MySQL (PDO)** y **Vanilla JavaScript (Fetch API)**. Permite a los usuarios compartir historias, interactuar mediante votos y comentarios anidados, personalizar su perfil y estado, y recibir notificaciones en tiempo real.

---

## Características Principales

### 1. Publicación y Exploración de Historias
- **Feed Dinámico:** Visualización de historias con carga asíncrona sin recargar la página.
- **Filtros Avanzados:** Filtrado combinable por **género**, **país**, **categoría** (*Foro Libre*, *Amor y Pareja*, *Salud Mental*, *Humor*) y **rango de edad**.
- **Ordenamiento:** Clasificación por **Recientes**, **Top** (balance neto entre *likes* y *dislikes*) y **Antiguos**.
- **Enlaces Permanentes y Open Graph:** Cada historia cuenta con su propia URL compartible (`?secreto=ID` o `/secreto/ID`) con inyección dinámica de metadatos `<title>` y `og:description` para previsualización en redes sociales (WhatsApp, X/Twitter).

### 2. Perfiles de Usuario y Estado Personalizable
- **Perfil Público e Interactivo:** Al hacer clic en el `@alias` del autor de cualquier secreto o comentario, se abre su perfil.
- **Estado Personalizado:** Los usuarios autenticados pueden definir y actualizar su estado personal (hasta 160 caracteres) y sus datos demográficos básicos (*género*, *edad*, *país*).
- **Historial de Actividad:** El perfil organiza en pestañas todos los **secretos publicados** y los **comentarios realizados** por el usuario, con acceso directo a cada conversación.

### 3. Comentarios Identificados y Respuestas Anidadas
- **Autoría Visible:** Todos los comentarios muestran el `@alias` real del usuario (con enlace directo a su perfil).
- **Hilos de Respuesta:** Soporte para responder a comentarios específicos (`parent_id`), mostrando visualmente a qué usuario se está respondiendo (`↪ En respuesta a @usuario`).

### 4. Sistema de Notificaciones
- **Alertas Automáticas:** Se generan notificaciones al instante cuando:
  - Alguien comenta en un secreto tuyo (`comment_secret`).
  - Alguien responde directamente a un comentario tuyo (`reply_comment`).
- **Centro de Notificaciones:** Indicador de notificaciones no leídas en la cabecera con actualización periódica (*polling*), vista previa del comentario, acceso directo al hilo y opción para marcar todas como leídas.

### 5. Seguridad y Arquitectura
- **Prevención de Inyección SQL:** Uso exclusivo de *Prepared Statements* nativos mediante **PDO** (`PDO::ATTR_EMULATE_PREPARES => false`).
- **Protección contra XSS:** Sanitización estricta de todas las salidas con `htmlspecialchars()`.
- **Autenticación Robusta:** Contraseñas hasheadas con `password_hash()` (`PASSWORD_DEFAULT`), verificación con `password_verify()`, *rehash* transparente y regeneración de ID de sesión (`session_regenerate_id(true)`) contra *Session Fixation*.
- **Consistencia de Datos:** Uso de transacciones SQL para el registro de votos y notificaciones.

---

## Stack Tecnológico

| Capa | Tecnología |
| :--- | :--- |
| **Backend** | PHP 8+ puro (arquitectura modular orientada a objetos y API JSON) |
| **Base de Datos** | MySQL / MariaDB con extensión **PDO** |
| **Frontend (Lógica)** | Vanilla JavaScript (ES6+, Fetch API, History API) |
| **Frontend (Diseño)** | HTML5 Semántico, CSS3 Variables, Tailwind CSS (CDN), Font Awesome 6 |
| **Servidor Web** | Apache (WAMP / XAMPP) con soporte `mod_rewrite` |

---

## Estructura del Proyecto

```text
clonts/
├── .htaccess          # Reglas mod_rewrite para URLs amigables (/secreto/123)
├── api.php            # Endpoint REST/JSON centralizado (secretos, votos, comentarios, perfiles, notificaciones)
├── app.js             # Lógica del cliente (Fetch API, renderizado DOM, modales, polling de notificaciones)
├── auth_class.php     # Clase Auth: registro, login, sesiones y actualización de perfil/estado
├── database.sql       # Script SQL completo con el esquema de base de datos
├── db_connect.php     # Conexión Singleton PDO y migración automática de esquema
├── index.php          # Interfaz principal SPA, modales y metadatos Open Graph
├── secreto.php        # Punto de entrada directo para enlaces de secretos individuales
└── styles.css         # Estilos personalizados y variables CSS3
```

---

## Instalación y Configuración

1. **Clonar el repositorio** dentro del directorio público de tu servidor web (por ejemplo `C:\wamp64\www\clonts` o `/var/www/html/clonts`):
   ```bash
   git clone https://github.com/JoelGaleano/clonts.git
   cd clonts
   ```

2. **Crear la Base de Datos:**
   - Importa el archivo [`database.sql`](database.sql) desde **phpMyAdmin** o mediante la consola de MySQL:
     ```bash
     mysql -u root -p < database.sql
     ```
   - *(Opcional)* El módulo [`db_connect.php`](db_connect.php) incluye `ensureSchemaUpgrades()`, que actualiza automáticamente las columnas y tablas nuevas si ya tenías una versión previa de la base de datos.

3. **Configurar credenciales MySQL:**
   - Verifica los parámetros de conexión en [`db_connect.php`](db_connect.php):
     ```php
     private const HOST = 'localhost';
     private const DB_NAME = 'clonts';
     private const USER = 'root';
     private const PASS = '';
     ```

4. **Ejecutar la aplicación:**
   - Inicia Apache y MySQL en WAMP/XAMPP y accede desde tu navegador a:
     ```text
     http://localhost/clonts/
     ```

---

## Endpoints de la API (`api.php`)

| Acción (`?action=...`) | Método | Descripción |
| :--- | :---: | :--- |
| `auth_status` | `GET` | Devuelve el estado de sesión actual, datos del usuario y conteo de notificaciones no leídas. |
| `register` | `POST` | Registra un nuevo usuario validando alias, email, fortaleza de contraseña y datos demográficos. |
| `login` | `POST` | Inicia sesión mediante correo electrónico o `@alias`. |
| `logout` | `GET` | Destruye la sesión activa de forma segura. |
| `get_profile` | `GET` | Obtiene los datos públicos, estado, secretos publicados y comentarios de un `user_id`. |
| `update_profile` | `POST` | Actualiza el estado (`status_message`), género, edad y país del usuario autenticado. |
| `get_secrets` | `GET` | Lista secretos aplicando filtros (`gender`, `country`, `category`, `min_age`, `max_age`, `sort`). |
| `get_secret` | `GET` | Obtiene un secreto específico por `id`. |
| `post_secret` | `POST` | Publica una nueva historia asociada al usuario en sesión. |
| `toggle_vote` | `POST` | Alterna voto positivo (`like`) o negativo (`dislike`) sobre un secreto. |
| `get_comments` | `GET` | Lista los comentarios y respuestas anidadas de un secreto junto con sus autores. |
| `post_comment` | `POST` | Publica un comentario o respuesta (`parent_id`) y dispara las notificaciones correspondientes. |
| `get_notifications` | `GET` | Devuelve las últimas notificaciones del usuario y el total de no leídas. |
| `mark_notifications_read` | `POST` | Marca una notificación específica o todas como leídas. |

---

## Autor y Créditos

Desarrollado por **Joel Galeano**  
- GitHub: [@JoelGaleano](https://github.com/JoelGaleano)
- Repositorio del Proyecto: [github.com/JoelGaleano/clonts](https://github.com/JoelGaleano/clonts)
