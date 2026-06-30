# Plan de Implementación Completo — Native Link Health v1.2.0
## Link Juice Dashboard + Correcciones de Seguridad y Calidad

**Versión del plan:** 1.0  
**Fecha:** 2026-06-26  
**Audiencia:** Agente de IA con capacidad de lectura/escritura de archivos  
**Plugin root:** `C:\xampp\htdocs\wpprueba\wp-content\plugins\native-link-health\`

---

## 📋 Índice de tareas

| # | Tarea | Archivos afectados | Complejidad | Depende de |
|---|-------|-------------------|-------------|------------|
| P0 | Fixes críticos de seguridad | 2 | Baja | — |
| P1 | Correcciones de bugs funcionales | 3 | Baja | P0 |
| P2 | Integración Broken Links → Link Juice | 4 | Alta | — |
| P3 | Nuevas 6 tarjetas + Health Score | 3 | Media | P2 |
| P4 | Vistas alternativas del grafo | 3 | Alta | P3 |
| P5 | Refactor seguridad AJAX | 3 | Media | — |
| P6 | Correcciones calidad y rendimiento | 4 | Baja | — |
| P7 | Accesibilidad SVG | 2 | Baja | P4 |

**Orden de ejecución:** P0 → P1 → P6 → P2 → P3 → P4 → P5 → P7  
(P0-P1-P6 son urgentes e independientes; P2-P3-P4-P7 son la refactorización del dashboard; P5 es opcional pero recomendado)

---

## P0 — FIXES CRÍTICOS DE SEGURIDAD

### P0.1 — `handle_export_csv()` sin verificación de capacidad

**Archivo:** `admin/class-nlh-admin.php`  
**Líneas:** 945-954  
**Tipo:** Seguridad (defense-in-depth)  
**Tiempo estimado:** 5 min

**Código actual:**
```php
public function handle_export_csv(): void {
    check_admin_referer( 'nlh_export_csv_action', 'nlh_export_nonce' );
    $export = new NLH_Export();
    $export->export_csv();
}
```

**Código nuevo:**
```php
public function handle_export_csv(): void {
    check_admin_referer( 'nlh_export_csv_action', 'nlh_export_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'native-link-health' ) );
    }

    $export = new NLH_Export();
    $export->export_csv();
}
```

**Riesgo:** Ninguno. `NLH_Export::export_csv()` ya hace el mismo check; es redundante pero defensivo.  
**Verificación:** Acceder a `admin-post.php?action=nlh_export_csv&nlh_export_nonce=INVALIDO` como subscriber debe dar 403.

---

### P0.2 — `uninstall.php` no limpia tablas v2.1 ni opciones

**Archivo:** `uninstall.php`  
**Líneas:** 1-55 (completo)  
**Tipo:** Data leak en desinstalación  
**Tiempo estimado:** 5 min

**Código actual** (después de la línea `$correction_log_table`):
```php
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
$wpdb->query( "DROP TABLE IF EXISTS {$events_table}" );
$wpdb->query( "DROP TABLE IF EXISTS {$correction_log_table}" );
```

**Código nuevo** (añadir ANTES de `delete_option` existentes):
```php
$link_map_table    = $wpdb->prefix . 'nlh_link_map';
$link_scores_table = $wpdb->prefix . 'nlh_link_scores';

$wpdb->query( "DROP TABLE IF EXISTS {$link_map_table}" );
$wpdb->query( "DROP TABLE IF EXISTS {$link_scores_table}" );
```

Y entre los `delete_option`:
```php
delete_option( 'nlh_juice_dirty' );
delete_option( 'nlh_juice_computed_at' );
```

**Riesgo:** Ninguno. Es código de desinstalación.  
**Verificación:** Tras desinstalar, `SHOW TABLES LIKE '%nlh_%'` debe devolver 0 resultados.

---

## P1 — CORRECCIONES DE BUGS FUNCIONALES

### P1.1 — Versión inconsistente (header 1.0.4 vs constante 1.1.0)

**Archivo:** `native-link-health.php`  
**Líneas:** 5 (header) y 21 (constante)  
**Tiempo estimado:** 1 min

**Cambio:** Unificar ambos valores. Asumiendo que la versión correcta es 1.1.0:
- Línea 5: Cambiar `Version: 1.0.4` → `Version: 1.1.0`
- Línea 21: Mantener `define( 'NLH_VERSION', '1.1.0' );`

**Verificación:** `grep 'Version:' native-link-health.php | head -1` debe mostrar `1.1.0`.

---

### P1.2 — `update_post_link()` sin `try/finally` (pérdida del hook save_post)

**Archivo:** `includes/class-nlh-scanner.php`  
**Líneas:** 928-944  
**Tiempo estimado:** 10 min

**Código actual (líneas 929-938):**
```php
if ( $updated ) {
    // Temporarily unhook handle_post_saved to avoid redundant re-scan.
    remove_action( 'save_post', array( $this, 'handle_post_saved' ), 10, 3 );
    $result = wp_update_post(
        array(
            'ID'           => $post_id,
            'post_content' => $processor->get_updated_html(),
        ),
        true
    );
    add_action( 'save_post', array( $this, 'handle_post_saved' ), 10, 3 );

    return ! is_wp_error( $result );
}
```

**Código nuevo:**
```php
if ( $updated ) {
    remove_action( 'save_post', array( $this, 'handle_post_saved' ), 10, 3 );
    try {
        $result = wp_update_post(
            array(
                'ID'           => $post_id,
                'post_content' => $processor->get_updated_html(),
            ),
            true
        );
    } finally {
        add_action( 'save_post', array( $this, 'handle_post_saved' ), 10, 3 );
    }

    return ! is_wp_error( $result );
}
```

**Riesgo:** Muy bajo. `finally` se ejecuta incluso si hay excepción. No cambia el flujo normal.  
**Verificación:** Forzar un error en `wp_update_post()` (ej: post_id inválido) y confirmar que el hook sigue registrado después.

---

### P1.3 — `class_exists('NLH_Link_Graph')` condicional que silencia errores

**Archivo:** `includes/class-nlh-scanner.php`  
**Líneas:** 86, 119, 285-286  
**Tiempo estimado:** 10 min

**Código actual (3 lugares):**
```php
if ( class_exists( 'NLH_Link_Graph' ) ) {
    ( new NLH_Link_Graph() )->compute_pagerank();
}
```

**Código nuevo (los 3 lugares):**
```php
if ( class_exists( 'NLH_Link_Graph' ) ) {
    ( new NLH_Link_Graph() )->compute_pagerank();
} else {
    error_log( 'Native Link Health: NLH_Link_Graph class not found — link juice features disabled in ' . __METHOD__ );
}
```

**Nota:** No eliminar el `class_exists()` porque el plugin carga dinámicamente las clases. Pero añadir el log permite diagnosticar fallos silenciosos.  
**Verificación:** Eliminar temporalmente `class-nlh-link-graph.php` y ejecutar un escaneo — debe aparecer un error en `debug.log`.

---

## P6 — CORRECCIONES DE CALIDAD Y RENDIMIENTO

### P6.1 — COUNT(*) redundante en `get_dashboard_data()`

**Archivo:** `admin/class-nlh-admin.php`  
**Líneas:** 1079 (línea exacta) y contexto 1072-1102  
**Tiempo estimado:** 5 min

**Problema:** `$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");` se ejecuta siempre, pero luego se sobrescribe si hay `$group_by`.

**Fix:** Mover la consulta COUNT dentro del bloque `else`:

```php
$total       = 0;
$total_pages = 1;

if ( in_array( $group_by, array( 'domain', 'error_type', 'post' ), true ) ) {
    $grouped     = $this->scanner->get_grouped_errors( $group_by, $paged, $per_page );
    $groups      = $grouped['groups'];
    $total       = (int) $grouped['total'];
    $total_pages = (int) $grouped['total_pages'];
} else {
    $total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    $total_pages = max( 1, (int) ceil( $total / $per_page ) );
    $rows = $wpdb->get_results( $wpdb->prepare( "..." , ... ) );
}
```

**Verificación:** Dashboard sin group_by debe mostrar el mismo total que antes.

---

### P6.2 — Transient `nlh_manual_scan_active` sin discriminar usuario

**Archivo:** `includes/class-nlh-scanner.php`  
**Líneas:** 59 (set_transient) y 21-31 (run_batch)  
**Tiempo estimado:** 15 min

**Fix:** Incluir `get_current_user_id()` en el transient:

```php
// Línea 59: cambiar a:
$user_id = get_current_user_id();
set_transient( 'nlh_manual_scan_active_' . $user_id, time(), 5 * MINUTE_IN_SECONDS );

// En run_batch(), reemplazar el check simple por consulta a options:
public function run_batch(): void {
    global $wpdb;
    $active = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} 
             WHERE option_name LIKE %s AND option_value > %d",
            $wpdb->esc_like( '_transient_nlh_manual_scan_active_' ) . '%',
            time() - 5 * MINUTE_IN_SECONDS
        )
    );

    if ( $active ) {
        return;
    }
    $this->scan_posts( $this->get_batch_posts() );
}
```

**Riesgo:** La query a `wp_options` añade overhead mínimo. Si hay problemas, el cron batch se ejecuta cuando un scan manual está activo (falso negativo) — no es crítico.  
**Verificación:** Iniciar un scan manual como admin A, verificar que admin B puede iniciar otro scan (no queda bloqueado por el transient de A).

---

### P6.3 — Interpolación directa de `pid` en selector querySelector

**Archivo:** `admin/js/nlh-juice.js`  
**Línea:** 665 (aprox)  
**Tiempo estimado:** 5 min

**Código actual:**
```js
var pid = btn.getAttribute( 'data-post-id' );
var row = document.querySelector( '.nlh-juice-table tr[data-post-id="' + pid + '"]' );
```

**Código nuevo:**
```js
var pid = btn.getAttribute( 'data-post-id' );
if ( ! /^\d+$/.test( pid ) ) { return; }
var row = document.querySelector( '.nlh-juice-table tr[data-post-id="' + pid + '"]' );
```

**Alternativa (más moderna):** Usar `CSS.escape()`:
```js
var row = document.querySelector( '.nlh-juice-table tr[data-post-id="' + CSS.escape( pid ) + '"]' );
```

**Verificación:** Click en botón de "jump to recommendation" debe hacer scroll a la fila correcta.

---

### P6.4 — `foreach` sobre `$rows` sin verificación

**Archivo:** `admin/partials/nlh-juice.php`  
**Líneas:** 46-48  
**Tiempo estimado:** 3 min

**Código actual:**
```php
foreach ( $rows as $nlh_row ) {
    $nlh_max_pr = max( $nlh_max_pr, (float) $nlh_row->pagerank );
}
```

**Código nuevo:**
```php
if ( ! empty( $rows ) && is_array( $rows ) ) {
    foreach ( $rows as $nlh_row ) {
        $nlh_max_pr = max( $nlh_max_pr, (float) $nlh_row->pagerank );
    }
}
```

**Verificación:** La página de Link Juice debe cargarse sin warnings aunque `$rows` esté vacío.

---

### P6.5 — Quantificador posesivo en regex de URLs

**Archivo:** `includes/class-nlh-scanner.php`  
**Línea:** 1076  
**Tiempo estimado:** 2 min

**Código actual:**
```php
preg_match_all( '/https?:\/\/[^\s<>"\'`\]]+/i', (string) $text, $matches );
```

**Código nuevo:**
```php
preg_match_all( '/https?:\/\/[^\s<>"\'`\]]++/i', (string) $text, $matches );
```

El `++` es quantificador posesivo — no backtrackea. PHP 8.0 lo soporta. No cambia qué URLs se capturan.  
**Verificación:** Escanear un post con URLs largas — debe capturar las mismas URLs que antes.

---

### P6.6 — `ob_clean()` solo limpia 1 nivel de buffer

**Archivo:** `admin/class-nlh-admin.php`  
**Líneas:** 1029-1033  
**Tiempo estimado:** 3 min

**Código actual:**
```php
private function clean_output_buffer(): void {
    if ( ob_get_level() ) {
        ob_clean();
    }
}
```

**Código nuevo:**
```php
private function clean_output_buffer(): void {
    while ( ob_get_level() > 0 ) {
        ob_end_clean();
    }
}
```

**Verificación:** Todas las respuestas AJAX del plugin deben seguir funcionando correctamente.

---

## P2 — INTEGRACIÓN BROKEN LINKS → LINK JUICE

### P2.1 — Nuevos índices en `nlh_link_errors`

**Archivo:** `includes/class-nlh-activator.php` (o donde se define el schema de `nlh_link_errors`)  
**Buscar** la definición de la tabla `nlh_link_errors` y añadir después del `UNIQUE KEY url_hash_post`:

```sql
INDEX url_hash (url_hash),
INDEX post_id_url_hash (post_id, url_hash)
```

**NOTA:** Si el plugin está en producción, ejecutar como migración en `update()`:
```php
$wpdb->query( "ALTER TABLE {$wpdb->prefix}nlh_link_errors ADD INDEX url_hash (url_hash)" );
$wpdb->query( "ALTER TABLE {$wpdb->prefix}nlh_link_errors ADD INDEX post_id_url_hash (post_id, url_hash)" );
```

---

### P2.2 — Nuevos métodos en `NLH_Link_Graph`

**Archivo:** `includes/class-nlh-link-graph.php`  
Añadir los siguientes métodos ANTES del cierre de la clase (`}`):

#### Método: `get_broken_link_counts()`
```php
/**
 * Returns broken outbound link count per post, with optional impact threshold.
 *
 * @param int $min_impact Minimum impact_score (0-100).
 * @return array<int,int> Keyed by source_post_id.
 */
public function get_broken_link_counts( int $min_impact = 0 ): array {
    global $wpdb;

    $map_table    = $wpdb->prefix . self::MAP_TABLE;
    $errors_table = $wpdb->prefix . 'nlh_link_errors';

    $impact_where = '';
    if ( $min_impact > 0 ) {
        $impact_where = $wpdb->prepare( ' AND e.impact_score >= %d', $min_impact );
    }

    $rows = $wpdb->get_results(
        "SELECT e.post_id, COUNT(DISTINCT e.url_hash) AS broken_count
         FROM {$errors_table} e
         INNER JOIN {$map_table} m ON m.source_post_id = e.post_id AND m.url_hash = e.url_hash
         WHERE 1=1{$impact_where}
         GROUP BY e.post_id",
        ARRAY_A
    );

    $out = array();
    foreach ( (array) $rows as $row ) {
        $out[ (int) $row['post_id'] ] = (int) $row['broken_count'];
    }
    return $out;
}
```

#### Método: `get_broken_count_for_post()`
```php
/**
 * Broken link count for a single post.
 *
 * @param int $post_id
 * @return int
 */
public function get_broken_count_for_post( int $post_id ): int {
    global $wpdb;
    $map_table    = $wpdb->prefix . self::MAP_TABLE;
    $errors_table = $wpdb->prefix . 'nlh_link_errors';

    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(DISTINCT e.url_hash)
             FROM {$errors_table} e
             INNER JOIN {$map_table} m ON m.source_post_id = e.post_id AND m.url_hash = e.url_hash
             WHERE e.post_id = %d",
            $post_id
        )
    );
}
```

#### Método: `get_broken_link_details_by_url()`
```php
/**
 * Detailed broken-link info for a URL hash.
 *
 * @param string $url_hash MD5 hash.
 * @return array|null
 */
public function get_broken_link_details_by_url( string $url_hash ): ?array {
    global $wpdb;

    $map_table    = $wpdb->prefix . self::MAP_TABLE;
    $errors_table = $wpdb->prefix . 'nlh_link_errors';
    $scores_table = $wpdb->prefix . self::SCORES_TABLE;

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT e.id, e.post_id, e.raw_url, e.status_code, e.error_message,
                    e.impact_score, e.discovered_at, e.last_checked_at,
                    m.target_url, m.anchor_text, m.link_type,
                    p.post_title AS source_title,
                    s.pagerank AS source_pagerank
             FROM {$errors_table} e
             INNER JOIN {$map_table} m ON m.source_post_id = e.post_id AND m.url_hash = e.url_hash
             LEFT JOIN {$wpdb->posts} p ON p.ID = e.post_id
             LEFT JOIN {$scores_table} s ON s.post_id = e.post_id
             WHERE e.url_hash = %s
             LIMIT 50",
            $url_hash
        )
    );

    if ( ! $row ) {
        return null;
    }

    return array(
        'error_id'       => (int) $row->id,
        'post_id'        => (int) $row->post_id,
        'raw_url'        => $row->raw_url,
        'status_code'    => (int) $row->status_code,
        'error_message'  => $row->error_message,
        'impact_score'   => (int) $row->impact_score,
        'discovered_at'  => $row->discovered_at,
        'last_checked_at'=> $row->last_checked_at,
        'target_url'     => $row->target_url,
        'anchor_text'    => $row->anchor_text,
        'source_title'   => $row->source_title,
        'source_pagerank'=> (float) $row->source_pagerank,
    );
}
```

#### Método: `calculate_health_score()`
```php
/**
 * Global Authority Health Score (0-100).
 *
 * Weights:
 * - 40%: Ratio of pages with inbound > 0 (not orphans)
 * - 30%: Ratio of pages with outbound_internal > 0 (not dead ends)
 * - 20%: Ratio of pages not diluted
 * - 10%: Ratio of healthy links (not broken)
 *
 * @return int 0-100
 */
public function calculate_health_score(): int {
    global $wpdb;

    $scores_table = $wpdb->prefix . self::SCORES_TABLE;
    $map_table    = $wpdb->prefix . self::MAP_TABLE;
    $errors_table = $wpdb->prefix . 'nlh_link_errors';

    $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$scores_table}" );
    if ( 0 === $total ) {
        return 0;
    }

    $excluded = array_filter( array(
        (int) get_option( 'page_on_front' ),
        (int) get_option( 'page_for_posts' ),
    ) );
    $exclude_sql = $excluded
        ? ' AND s.post_id NOT IN (' . implode( ',', array_map( 'intval', $excluded ) ) . ')'
        : '';

    $with_inbound = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$scores_table} s WHERE s.inbound_internal > 0{$exclude_sql}"
    );
    $orphan_ratio = $with_inbound / $total;

    $with_outbound = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$scores_table} s WHERE s.outbound_internal > 0"
    );
    $deadend_ratio = $with_outbound / $total;

    $threshold   = self::get_dilution_threshold();
    $not_diluted = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$scores_table} s WHERE s.outbound_total <= %d",
            $threshold
        )
    );
    $diluted_ratio = $not_diluted / $total;

    $total_broken = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT e.url_hash)
         FROM {$errors_table} e
         INNER JOIN {$map_table} m ON m.source_post_id = e.post_id AND m.url_hash = e.url_hash"
    );
    $total_links_in_map = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT url_hash) FROM {$map_table} WHERE link_type = 'internal'"
    );
    $healthy_links_ratio = $total_links_in_map > 0
        ? ( $total_links_in_map - $total_broken ) / $total_links_in_map
        : 1;

    $score = ( $orphan_ratio * 40 )
           + ( $deadend_ratio * 30 )
           + ( $diluted_ratio * 20 )
           + ( $healthy_links_ratio * 10 );

    return (int) round( min( 100, max( 0, $score ) ) );
}
```

#### Métodos de cache:
```php
/**
 * Cached broken counts via transient/object cache.
 */
public function get_cached_broken_counts( int $min_impact = 0 ): array {
    $cache_key = 'nlh_broken_counts_' . $min_impact;
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        return $cached;
    }
    $counts = $this->get_broken_link_counts( $min_impact );
    set_transient( $cache_key, $counts, 5 * MINUTE_IN_SECONDS );
    return $counts;
}

/**
 * Invalidate broken counts cache.
 */
public static function clear_broken_counts_cache(): void {
    delete_transient( 'nlh_broken_counts_0' );
    delete_transient( 'nlh_broken_counts_20' );
    delete_transient( 'nlh_broken_counts_50' );
}
```

---

### P2.3 — Modificar `get_graph()` para incluir datos de broken links

**Archivo:** `includes/class-nlh-link-graph.php`  
**Método:** `get_graph()` (buscar la definición, aprox línea 729)

**Cambios:**
1. Antes de la query principal, precargar `$broken_counts` (usar `get_cached_broken_counts()` si el cache existe, si no hacer la query directa con JOIN)
2. En el bucle de nodos, añadir `broken_count` y una query opcional para `broken_urls` (top 3)

**Estructura de nodo modificada:**
```php
$nodes[] = array(
    'id'           => $pid,
    'title'        => (string) $row->post_title,
    'pr'           => (float) $row->pagerank,
    'inb'          => (int) $row->inbound_internal,
    'out'          => (int) $row->outbound_internal,
    'flag'         => $flag,
    'broken_count' => $broken,
    'broken_urls'  => $broken_urls_array, // array of ['url'=>'...','status'=>404]
    'impact_score' => $max_impact,
);
```

**Regla de `flag` modificada:**
- Si `$broken > 0` → flag = `'has_broken'` (prioridad sobre orphan/deadend/diluted)
- Si no, mantener la lógica actual

---

### P2.4 — Modificar `get_flow()` para incluir broken info en outbound

**Archivo:** `includes/class-nlh-link-graph.php`  
**Método:** `get_flow()`

**Cambio:** Después de obtener `$outbound`, enriquecer cada outbound con datos de `nlh_link_errors`:

```php
// Obtener hashes de todas las URLs outbound
$url_hashes = array();
foreach ( $outbound as $ob ) {
    $url_hashes[] = md5( $ob['target_url'] );
}

// Batch query a nlh_link_errors
if ( ! empty( $url_hashes ) ) {
    $placeholders = implode( ',', array_fill( 0, count( $url_hashes ), '%s' ) );
    $params       = array_merge( array( $post_id ), $url_hashes );
    $broken_rows  = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT url_hash, status_code, error_message, impact_score
             FROM {$errors_table}
             WHERE post_id = %d AND url_hash IN ({$placeholders})",
            $params
        ),
        ARRAY_A
    );
    $broken_by_hash = array();
    foreach ( (array) $broken_rows as $br ) {
        $broken_by_hash[ $br['url_hash'] ] = $br;
    }
    foreach ( $outbound as &$ob ) {
        $hash = md5( $ob['target_url'] );
        if ( isset( $broken_by_hash[ $hash ] ) ) {
            $ob['is_broken']     = true;
            $ob['status_code']   = (int) $broken_by_hash[ $hash ]['status_code'];
            $ob['error_message'] = $broken_by_hash[ $hash ]['error_message'];
            $ob['impact_score']  = (int) $broken_by_hash[ $hash ]['impact_score'];
        } else {
            $ob['is_broken'] = false;
        }
    }
    unset( $ob );
}
```

---

### P2.5 — Modificar `get_summary()` para incluir broken info

**Archivo:** `includes/class-nlh-link-graph.php`  
**Método:** `get_summary()`

**Añadir al array de retorno:**
```php
'total_broken'      => (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT e.url_hash)
     FROM {$errors_table} e
     INNER JOIN {$map_table} m ON m.source_post_id = e.post_id AND m.url_hash = e.url_hash"
),
'pages_with_broken' => (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT e.post_id)
     FROM {$errors_table} e
     INNER JOIN {$map_table} m ON m.source_post_id = e.post_id AND m.url_hash = e.url_hash"
),
'broken_4xx'        => (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT e.url_hash)
     FROM {$errors_table} e
     INNER JOIN {$map_table} m ON m.source_post_id = e.post_id AND m.url_hash = e.url_hash
     WHERE e.status_code >= 400 AND e.status_code < 500"
),
'broken_5xx'        => (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT e.url_hash)
     FROM {$errors_table} e
     INNER JOIN {$map_table} m ON m.source_post_id = e.post_id AND m.url_hash = e.url_hash
     WHERE e.status_code >= 500"
),
'broken_timeout'    => (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT e.url_hash)
     FROM {$errors_table} e
     INNER JOIN {$map_table} m ON m.source_post_id = e.post_id AND m.url_hash = e.url_hash
     WHERE e.status_code = 0"
),
'health_score'      => $this->calculate_health_score(),
```

---

### P2.6 — Nuevo AJAX handler en `NLH_Admin`

**Archivo:** `admin/class-nlh-admin.php`

**Añadir en `__construct()`:**
```php
add_action( 'wp_ajax_nlh_juice_broken_details', array( $this, 'ajax_juice_broken_details' ) );
```

**Añadir el método handler:**
```php
/**
 * AJAX handler: returns broken-link details for a URL hash.
 */
public function ajax_juice_broken_details(): void {
    $this->run_ajax_safe( function () {
        $this->verify_ajax_request();

        $post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $url_hash = isset( $_POST['url_hash'] )
            ? sanitize_text_field( wp_unslash( $_POST['url_hash'] ) )
            : '';

        if ( ! $post_id || '' === $url_hash ) {
            $this->clean_output_buffer();
            wp_send_json_error(
                array( 'message' => __( 'Missing parameters.', 'native-link-health' ) ),
                400
            );
        }

        $details = ( new NLH_Link_Graph() )->get_broken_link_details_by_url( $url_hash );

        $this->clean_output_buffer();
        wp_send_json_success( $details );
    } );
}
```

---

### P2.7 — Limpiar cache en recompute

**Archivo:** `admin/class-nlh-admin.php`  
**Método:** `ajax_recompute_juice()`

**Añadir al inicio del handler:**
```php
NLH_Link_Graph::clear_broken_counts_cache();
```

---

### P2.8 — Localize data para nuevo endpoint

**Archivo:** `admin/class-nlh-admin.php`  
**Buscar** `wp_localize_script('nlh-juice', 'nlh_juice', ...)`

**Añadir al array:**
```php
'brokenDetailsAction' => 'nlh_juice_broken_details',
'i18n' => array_merge( $i18n_existente, array(
    'brokenLinks'      => __( 'Broken links', 'native-link-health' ),
    'noBroken'         => __( 'No broken links found.', 'native-link-health' ),
    'brokenFound'      => __( '%d broken link(s) found', 'native-link-health' ),
    'statusCode'       => __( 'Status: %d', 'native-link-health' ),
    'legendBroken'     => __( 'Has broken links', 'native-link-health' ),
) ),
```

---

## P3 — NUEVAS 6 TARJETAS + HEALTH SCORE

### P3.1 — Modificar `render_juice_page()`

**Archivo:** `admin/class-nlh-admin.php`  
**Buscar** `public function render_juice_page(): void` (aprox línea 360)

**Cambios en el bloque que obtiene datos:**
```php
// Después de obtener $report, añadir:
$summary          = $graph->get_summary();
$health_score     = $graph->calculate_health_score();
```

Pasar estas variables al template (ya se usa `include` para `nlh-juice.php`).

---

### P3.2 — Reemplazar las tarjetas en el template

**Archivo:** `admin/partials/nlh-juice.php`  
**Reemplazar** el bloque `<div class="nlh-metrics-grid">...</div>` (líneas 146-175 aprox) con:

```php
<div class="nlh-metrics-grid nlh-juice-summary">

    <div class="nlh-metric-card"
         title="<?php esc_attr_e( 'Total published pages with link authority data.', 'native-link-health' ); ?>">
        <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
        <span class="nlh-metric-value"><?php echo esc_html( number_format_i18n( (int) $summary['total'] ) ); ?></span>
        <span class="nlh-metric-label"><?php esc_html_e( 'Pages analyzed', 'native-link-health' ); ?></span>
    </div>

    <div class="nlh-metric-card"
         title="<?php esc_attr_e( 'Global health of your internal link authority (0-100). Higher is better.', 'native-link-health' ); ?>">
        <span class="dashicons dashicons-chart-area" aria-hidden="true"></span>
        <span class="nlh-metric-value" style="color:<?php echo $health_score >= 70 ? '#2a9d3f' : ( $health_score >= 40 ? '#dba617' : '#d63638' ); ?>">
            <?php echo esc_html( $health_score ); ?>/100
        </span>
        <span class="nlh-metric-label"><?php esc_html_e( 'Authority Health', 'native-link-health' ); ?></span>
    </div>

    <div class="nlh-metric-card"
         title="<?php esc_attr_e( 'Pages with zero inbound internal links. They receive no link juice.', 'native-link-health' ); ?>">
        <span class="dashicons dashicons-warning" aria-hidden="true"></span>
        <span class="nlh-metric-value"><?php echo esc_html( number_format_i18n( (int) $summary['orphans'] ) ); ?></span>
        <span class="nlh-metric-label"><?php esc_html_e( 'Orphans (no inbound)', 'native-link-health' ); ?></span>
    </div>

    <div class="nlh-metric-card"
         title="<?php esc_attr_e( 'Pages with inbound links but no internal outbound links. Authority stops here.', 'native-link-health' ); ?>">
        <span class="dashicons dashicons-editor-break" aria-hidden="true"></span>
        <span class="nlh-metric-value"><?php echo esc_html( number_format_i18n( (int) $summary['deadEnds'] ) ); ?></span>
        <span class="nlh-metric-label"><?php esc_html_e( 'Dead ends', 'native-link-health' ); ?></span>
    </div>

    <?php $threshold = NLH_Link_Graph::get_dilution_threshold(); ?>
    <div class="nlh-metric-card"
         title="<?php esc_attr_e( sprintf( __( 'Pages with more than %d outbound links. Link juice spread too thin.', 'native-link-health' ), (int) $threshold ) ); ?>">
        <span class="dashicons dashicons-filter" aria-hidden="true"></span>
        <span class="nlh-metric-value"><?php echo esc_html( number_format_i18n( (int) $summary['diluted'] ) ); ?></span>
        <span class="nlh-metric-label"><?php printf( esc_html__( 'Diluted (>%d links)', 'native-link-health' ), (int) $threshold ); ?></span>
    </div>

    <div class="nlh-metric-card <?php echo (int) $summary['total_broken'] > 0 ? 'nlh-card-broken' : ''; ?>"
         title="<?php esc_attr_e( 'Broken outbound links detected. These leak authority and hurt UX.', 'native-link-health' ); ?>">
        <span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
        <span class="nlh-metric-value">
            <?php if ( (int) $summary['total_broken'] > 0 ) : ?>
                <?php echo esc_html( sprintf( __( '%d / %d', 'native-link-health' ), (int) $summary['total_broken'], (int) $summary['pages_with_broken'] ) ); ?>
            <?php else : ?>
                0
            <?php endif; ?>
        </span>
        <span class="nlh-metric-label">
            <?php (int) $summary['total_broken'] > 0
                ? esc_html_e( 'Broken links / affected pages', 'native-link-health' )
                : esc_html_e( 'No broken links', 'native-link-health' );
            ?>
        </span>
        <?php if ( (int) $summary['total_broken'] > 0 ) : ?>
            <div class="nlh-metric-sub">
                <?php echo esc_html( sprintf(
                    __( '%d 4xx, %d 5xx, %d timeouts', 'native-link-health' ),
                    (int) $summary['broken_4xx'],
                    (int) $summary['broken_5xx'],
                    (int) $summary['broken_timeout']
                ) ); ?>
            </div>
        <?php endif; ?>
    </div>

</div>
```

**NOTA:** Asegurarse de que `$summary` y `$health_score` están disponibles en el template. En `render_juice_page()`:
```php
$summary      = $graph->get_summary();
$health_score = $graph->calculate_health_score();
```

---

### P3.3 — CSS para nuevas tarjetas

**Archivo:** `admin/css/nlh-admin.css`  
**Añadir al final:**

```css
.nlh-metric-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 6px;
    padding: 16px;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    position: relative;
}
.nlh-metric-card .dashicons {
    font-size: 20px;
    width: 20px;
    height: 20px;
    color: #787c82;
    margin-bottom: 4px;
}
.nlh-metric-value {
    font-size: 22px;
    font-weight: 600;
    line-height: 1.2;
    color: #1d2327;
}
.nlh-metric-label {
    font-size: 12px;
    color: #787c82;
    line-height: 1.4;
}
.nlh-metric-sub {
    font-size: 11px;
    color: #8c8f94;
    margin-top: 2px;
}
.nlh-card-broken .nlh-metric-value {
    color: #d63638;
}
```

---

## P4 — VISTAS ALTERNATIVAS DEL GRAFO

### P4.1 — Selector de vistas en el toolbar

**Archivo:** `admin/partials/nlh-juice.php`  
**Buscar** el toolbar del overview y añadir:

```php
<div class="nlh-view-selector">
    <button type="button" class="button button-small is-active" data-view="force">
        <?php esc_html_e( 'Force Graph', 'native-link-health' ); ?>
    </button>
    <button type="button" class="button button-small" data-view="rings">
        <?php esc_html_e( 'Concentric', 'native-link-health' ); ?>
    </button>
    <button type="button" class="button button-small" data-view="scatter">
        <?php esc_html_e( 'Scatter', 'native-link-health' ); ?>
    </button>
</div>
```

---

### P4.2 — Funciones JS de vistas alternativas

**Archivo:** `admin/js/nlh-juice.js`

**Añadir 3 funciones de renderizado:**

#### `renderConcentric(nodes, canvas, W, H)`
- Ordenar nodos por PageRank descendente
- Dividir en 4 anillos (Homepage → Pillar → Category → Article)
- Cada anillo con radio fijo: [60, 140, 210, 280]
- Ángulo distribuido uniformemente dentro del anillo
- Llamar a `renderGraphElements()` con las posiciones calculadas (sin simulación)

#### `renderBubbleScatter(nodes, canvas, W, H)`
- Eje X: inbound links (normalizado)
- Eje Y: outbound links (normalizado)
- Tamaño burbuja: √(PageRank) * escala
- Color: por flag (orphan=red, deadend=yellow, diluted=blue, ok=green, has_broken=dark-red)
- Añadir ejes X/Y con labels

#### `renderGraphElements(nodes, byId, edges, W, H)` — Helper refactorizado
- Extraer la lógica de dibujado de `renderOverview()` a esta función
- Usar el array de nodos con posiciones precalculadas

---

### P4.3 — Selector de vistas en JS

```js
// Event listener para el view selector
document.querySelectorAll( '.nlh-view-selector .button' ).forEach( function ( btn ) {
    btn.addEventListener( 'click', function () {
        document.querySelectorAll( '.nlh-view-selector .button' ).forEach( function ( b ) {
            b.classList.remove( 'is-active' );
        } );
        btn.classList.add( 'is-active' );

        var view = btn.getAttribute( 'data-view' );
        var canvas = document.getElementById( 'nlh-juice-overview' );
        // Re-render con la vista seleccionada
        if ( 'force' === view ) {
            renderOverview( canvas, currentData );
        } else if ( 'rings' === view ) {
            renderConcentric( currentData.nodes, canvas, 900, 560 );
        } else if ( 'scatter' === view ) {
            renderBubbleScatter( currentData.nodes, canvas, 900, 560 );
        }
    } );
} );
```

---

### P4.4 — Barnes-Hut para >200 nodos

**Archivo:** `admin/js/nlh-juice.js`  
**En la función `simulate()`** (aprox línea 502), añadir al inicio:

```js
if ( nodes.length > 200 ) {
    return simulateBarnesHut( nodes, links, byId, W, H );
}
```

**Añadir la función `simulateBarnesHut()`** que implementa:
1. Construcción de quadtree (nodos como puntos con masa)
2. Cálculo de centroides (post-order traversal)
3. Cálculo de fuerzas: si `s / d < theta` (default 0.8), aproximar masa; si no, profundizar
4. Integración velocity Verlet (igual que la simulación actual)
5. 220 iteraciones con cooling linear

---

## P5 — REFACTOR SEGURIDAD AJAX

### P5.1 — Modificar `verify_ajax_request()` para aceptar action

**Archivo:** `admin/class-nlh-admin.php`  
**Buscar:** `private function verify_ajax_request(): void`

**Código nuevo:**
```php
private function verify_ajax_request( string $action = 'nlh_ajax_nonce' ): void {
    check_ajax_referer( $action, 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        $this->clean_output_buffer();
        wp_send_json_error(
            array( 'message' => __( 'Insufficient permissions.', 'native-link-health' ) ),
            403
        );
    }
}
```

---

### P5.2 — Generar nonces por acción en el frontend

**Archivo:** `admin/class-nlh-admin.php`  
**Buscar** `wp_localize_script('nlh-juice', 'nlh_juice', ...)`

**Cambiar el array de nonces:**
```php
'nonces' => array(
    'recompute'      => wp_create_nonce( 'nlh_recompute_juice' ),
    'details'        => wp_create_nonce( 'nlh_juice_details' ),
    'graph'          => wp_create_nonce( 'nlh_juice_graph' ),
    'relink'         => wp_create_nonce( 'nlh_juice_relink' ),
    'broken_details' => wp_create_nonce( 'nlh_juice_broken_details' ),
),
```

**Mantener el nonce legacy** para compatibilidad:
```php
'nonce' => wp_create_nonce( 'nlh_ajax_nonce' ),
```

---

### P5.3 — Modificar JS para usar nonce específico

**Archivo:** `admin/js/nlh-juice.js`  
**Buscar** cada uso de `nlh_juice.nonce` y reemplazar por el nonce específico:

```js
// Antes:
nonce: nlh_juice.nonce

// Después (ejemplo para recompute):
nonce: nlh_juice.nonces.recompute
```

**Mapeo de endpoints a nonces:**
| Endpoint | Nonce |
|----------|-------|
| `nlh_recompute_juice` | `nlh_juice.nonces.recompute` |
| `nlh_juice_details` | `nlh_juice.nonces.details` |
| `nlh_juice_graph` | `nlh_juice.nonces.graph` |
| `nlh_juice_relink` | `nlh_juice.nonces.relink` |
| `nlh_juice_broken_details` | `nlh_juice.nonces.broken_details` |

**Para los handlers del dashboard/scanner** (no Link Juice), mantener `nlh_ajax.nonce` (ya usan nonce separado).

---

### P5.4 — Unificar `ajax_run_now`

**Archivo:** `admin/class-nlh-admin.php`  
**Buscar** `public function ajax_run_now(): void`

**Cambio:** Si se decide migrar a `verify_ajax_request()`:
```php
public function ajax_run_now(): void {
    $this->run_ajax_safe( function () {
        $this->verify_ajax_request( 'nlh_run_now_action' );
        // ... resto del código igual ...
    } );
}
```

---

### P5.5 — Validar registro en `ajax_ignore_url`

**Archivo:** `admin/class-nlh-admin.php`  
**Buscar** `public function ajax_ignore_url(): void`

**Añadir ANTES del DELETE:**
```php
$existing = $wpdb->get_var( $wpdb->prepare(
    "SELECT id FROM {$table} WHERE url_hash = %s AND post_id = %d",
    $url_hash,
    $post_id
) );
if ( ! $existing ) {
    $this->clean_output_buffer();
    wp_send_json_error(
        array( 'message' => __( 'Record not found.', 'native-link-health' ) ),
        404
    );
}
```

---

## P3 (alternativa) — CHUNKING DE `posts_per_page => -1`

### P3.1 — Refactor `rebuild_all()`

**Archivo:** `includes/class-nlh-link-graph.php`  
**Método:** `rebuild_all()` (buscar la query con `posts_per_page => -1`)

**Código nuevo:**
```php
public function rebuild_all(): array {
    global $wpdb;

    $post_types = apply_filters( 'nlh_scan_post_types', array( 'post', 'page' ) );
    $total      = 0;
    $page       = 1;
    $chunk_size = 100;

    do {
        $query = new WP_Query( array(
            'post_type'              => $post_types,
            'post_status'            => 'publish',
            'posts_per_page'         => $chunk_size,
            'fields'                 => 'ids',
            'paged'                  => $page,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'ignore_sticky_posts'    => true,
            'no_found_rows'          => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ) );

        foreach ( $query->posts as $post_id ) {
            $post = get_post( (int) $post_id );
            if ( $post instanceof WP_Post ) {
                $this->record_post( (int) $post_id, $post->post_content );
            }
        }

        $total += count( $query->posts );
        $page++;
    } while ( ! empty( $query->posts ) );

    return $this->compute_pagerank();
}
```

---

### P3.2 — Refactor `get_node_ids()`

```php
private function get_node_ids(): array {
    global $wpdb;
    $post_types = apply_filters( 'nlh_scan_post_types', array( 'post', 'page' ) );
    $post_types = array_values( array_filter( array_map( 'sanitize_key', (array) $post_types ) ) );

    if ( empty( $post_types ) ) {
        return array();
    }

    $placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

    return array_map( 'intval', (array) $wpdb->get_col(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type IN ({$placeholders}) AND post_status = 'publish'
             ORDER BY ID ASC",
            $post_types
        )
    ) );
}
```

---

### P3.3 — Refactor `get_public_posts()` en SEO Audit

**Archivo:** `includes/class-nlh-seo-audit.php`  
**Método:** `get_public_posts()` (aprox línea 173)

```php
private function get_public_posts(): array {
    $all_posts = array();
    $paged     = 1;

    do {
        $posts = get_posts( array(
            'post_type'      => array( 'post', 'page' ),
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'paged'          => $paged,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ) );

        $all_posts = array_merge( $all_posts, $posts );
        $paged++;
    } while ( count( $posts ) === 100 );

    return $all_posts;
}
```

---

## P7 — ACCESIBILIDAD SVG

### P7.1 — ARIA en el SVG del overview

**Archivo:** `admin/js/nlh-juice.js`  
**En la función que crea el SVG root:**

```js
var root = svg( 'svg', {
    viewBox: '0 0 ' + W + ' ' + H,
    class: 'nlh-overview-svg',
    role: 'img',
    'aria-label': i18n.overviewHint || 'Site authority map. Bigger nodes hold more authority.'
} );
```

### P7.2 — Cada nodo con `role="button"` y `tabindex`

```js
var g = svg( 'g', {
    class: 'nlh-ov-node ' + cls,
    transform: 'translate(' + n.x + ',' + n.y + ')',
    role: 'button',
    tabindex: '0',
    'aria-label': n.title + ' — ' + ( n.pr * 100 ).toFixed( 2 ) + '%'
        + ( n.broken_count ? ' — ' + n.broken_count + ' broken links' : '' )
} );
```

### P7.3 — Keyboard events en nodos

```js
g.addEventListener( 'keydown', function ( ev ) {
    if ( ev.key === 'Enter' || ev.key === ' ' ) {
        ev.preventDefault();
        focusNode( n.id, byId, nodeEls, gEdges );
    }
} );

g.addEventListener( 'keyup', function ( ev ) {
    if ( ev.key === 'Escape' ) {
        clearFocus( nodeEls, gEdges );
    }
} );
```

### P7.4 — Aria-live region para actualizaciones dinámicas

```js
var liveRegion = document.createElement( 'div' );
liveRegion.setAttribute( 'aria-live', 'polite' );
liveRegion.setAttribute( 'class', 'screen-reader-text' );
canvas.parentNode.appendChild( liveRegion );

// Cuando se selecciona un nodo:
liveRegion.textContent = 'Selected: ' + nodeTitle;
```

---

## 🧪 VERIFICACIÓN GENERAL

Después de implementar TODAS las tareas, ejecutar estas verificaciones:

### Funcionalidad
- [ ] Página Link Juice carga sin errores JS ni warnings PHP
- [ ] 6 tarjetas de métricas visibles con datos correctos
- [ ] Health Score entre 0-100, color cambia según rango
- [ ] Force graph se renderiza con nodos que muestran broken_count
- [ ] Concentric Rings y Scatter se renderizan al cambiar vista
- [ ] Click en nodo → panel de detalles con broken links listados
- [ ] Recalcular Link Juice funciona y actualiza datos
- [ ] Botón "Recompute" limpia cache de broken counts
- [ ] Dashboard de errores (scanner) sin regresiones

### Seguridad
- [ ] Export CSV como subscriber devuelve 403
- [ ] AJAX con nonce incorrecto devuelve error
- [ ] Uninstall elimina todas las tablas `nlh_*`
- [ ] `ajax_ignore_url` con URL inexistente devuelve 404

### Rendimiento
- [ ] Sitio con 10,000+ posts no crashea al recalcular
- [ ] Dashboard carga en <2s con cache warm

### Accesibilidad
- [ ] SVG tiene `role="img"` + `aria-label`
- [ ] Nodos tienen `role="button"` + `tabindex="0"`
- [ ] Tab + Enter selecciona un nodo
- [ ] Escape deselecciona

---

## 📁 RESUMEN DE ARCHIVOS MODIFICADOS

| Archivo | Tareas |
|---------|--------|
| `native-link-health.php` | P1.1 (version header) |
| `uninstall.php` | P0.2 (nuevas tablas/opciones) |
| `admin/class-nlh-admin.php` | P0.1, P2.6, P2.7, P2.8, P5.1, P5.2, P5.4, P5.5, P6.1, P6.6, P3.1 |
| `admin/partials/nlh-juice.php` | P3.2, P4.1, P6.4 |
| `admin/js/nlh-juice.js` | P4.2, P4.3, P4.4, P5.3, P6.3, P7.1, P7.2, P7.3, P7.4 |
| `admin/css/nlh-admin.css` | P3.3 |
| `includes/class-nlh-link-graph.php` | P2.2, P2.3, P2.4, P2.5, P3.1 (chunking), P3.2 (chunking) |
| `includes/class-nlh-scanner.php` | P1.2, P1.3, P6.2, P6.5 |
| `includes/class-nlh-seo-audit.php` | P3.3 (chunking) |
| `includes/class-nlh-activator.php` | P2.1 (índices) |

**Total: 11 archivos modificados, ~20 tareas.**

---

## ⚠️ RIESGOS GLOBALES

1. **Regresión en el dashboard de errores (scanner):** Las modificaciones en `get_summary()` y `get_graph()` son aditivas, no modifican la lógica existente. Pero verificar que los datos que consume el scanner no cambien de formato.

2. **Rendimiento de JOIN queries:** Las nuevas queries que cruzan `nlh_link_errors` con `nlh_link_map` usan índices, pero en sitios con +50,000 errores pueden ser lentas. El cache de 5 minutos mitiga esto.

3. **JS existente:** Las nuevas vistas (rings, scatter) no modifican el código de renderizado existente; son aditivas. Pero el selector de vistas introduce un event listener que podría conflictuar si hay otros listeners en los botones.

4. **Nonces por acción:** Cambiar los nonces rompe cualquier integración externa que llame a los AJAX endpoints con el nonce legacy. Mantener `'nonce' => wp_create_nonce('nlh_ajax_nonce')` como respaldo durante el periodo de transición.

5. **Posts per_page chunking:** Cambiar de -1 a 100 cambia el perfil de rendimiento: más queries DB pero menos memoria PHP. En sitios pequeños (<500 posts) es equivalente. En sitios grandes (+10,000) evita el crash.

---

*Fin del plan de implementación. Evaluar y proceder con P0 → P1 → P6 → P2 → P3 → P4 → P5 → P7.*
