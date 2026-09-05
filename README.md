# Ecom Layered Table

**PrestaShop 8+ · Control y limpieza del caché de navegación por capas**

[![PrestaShop](https://img.shields.io/badge/PrestaShop-8%2B-blue)](https://www.prestashop-project.org/)
[![PHP](https://img.shields.io/badge/PHP-compatible-blue)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-AFL--3.0-green)](https://opensource.org/licenses/AFL-3.0)

**Ecom Layered Table** es un módulo para PrestaShop diseñado para evitar que el caché del módulo **ps_facetedsearch** crezca de forma descontrolada.

El módulo controla principalmente la tabla:

```text
ps_layered_filter_block
```

y permite limitar su tamaño, eliminar entradas antiguas o poco utilizadas, evitar que determinados robots y combinaciones de filtros generen caché innecesario y realizar tareas de mantenimiento de forma automática.

> Diseñado especialmente para tiendas con catálogos grandes y navegación por filtros que generan miles o millones de combinaciones.

---

## 🚀 ¿Qué problema resuelve?

El sistema de navegación por capas de PrestaShop (`ps_facetedsearch`) utiliza una tabla de caché para almacenar los resultados de las combinaciones de filtros.

En tiendas con:

* muchos productos,
* muchas categorías,
* múltiples atributos,
* numerosos fabricantes,
* filtros por precio,
* filtros por peso,
* múltiples idiomas,
* múltiples monedas,
* mucho tráfico,
* crawlers y bots,

la tabla de caché puede crecer considerablemente.

Esto puede provocar:

* aumento del tamaño de la base de datos;
* mayor consumo de disco;
* tablas de varios GB;
* operaciones de limpieza cada vez más lentas;
* generación de combinaciones de filtros que prácticamente nunca vuelven a utilizarse;
* consultas y operaciones de mantenimiento costosas.

**Ecom Layered Table** controla este crecimiento y mantiene el caché dentro de unos límites configurables.

---

# ✨ Características

## 🧹 Limpieza automática

El módulo puede ejecutar automáticamente tareas de mantenimiento sobre el caché.

La limpieza puede realizarse desde:

* Front Office
* Back Office
* Cron
* ejecución manual

Cada ejecución trabaja con un **límite de tiempo configurable**, evitando que una operación de mantenimiento pesada bloquee indefinidamente una petición.

---

## 📏 Límite máximo de tamaño

Puedes establecer un tamaño máximo para la tabla de caché.

Por ejemplo:

```text
950 MB
```

Cuando se supera el límite, el módulo elimina entradas hasta alcanzar el porcentaje objetivo configurado.

Configuración por defecto:

```text
Tamaño máximo:       950 MB
Objetivo después de limpieza: 70 %
```

Es decir, si la tabla supera el límite, el sistema intenta reducirla aproximadamente hasta el 70 % del máximo establecido.

---

## 🗑️ Limpieza por antigüedad

Las entradas antiguas pueden eliminarse automáticamente.

Configuración predeterminada:

```text
TTL: 7 días
```

Esto permite eliminar cachés que llevan demasiado tiempo almacenados.

---

## ♻️ Limpieza por falta de uso

El módulo registra cuándo se utiliza cada entrada de caché.

Una entrada que no se haya utilizado durante un período configurable puede eliminarse.

Valor predeterminado:

```text
Entradas sin uso: 3 días
```

Esto permite conservar las combinaciones realmente utilizadas y eliminar las que se han quedado obsoletas.

---

## 📊 Seguimiento del uso

El módulo mantiene una tabla de metadatos para saber qué entradas del caché se están utilizando.

Registra información como:

* hash de la entrada;
* fecha de creación;
* última utilización;
* número de usos;
* tamaño estimado.

Esto permite aplicar estrategias de limpieza basadas en el uso real.

---

## 🤖 Exclusión de bots

Una de las funciones más importantes del módulo es evitar que los robots llenen el caché de navegación por capas.

Por defecto identifica User-Agents como:

```text
Googlebot
Bingbot
Yandex
Baiduspider
DuckDuckBot
AhrefsBot
SemrushBot
MJ12bot
DotBot
PetalBot
AppleBot
Bytespider
GPTBot
ClaudeBot
CCBot
AmazonBot
FacebookExternalHit
Screaming Frog
curl
wget
python-requests
crawler
spider
```

La lista es configurable.

Cuando se detecta un bot, el módulo puede impedir que esa petición utilice/genere el caché de navegación por capas.

---

## 💰 Exclusión de rangos de precio

Los filtros por rangos de precio pueden producir una enorme cantidad de combinaciones diferentes.

Por defecto, el módulo evita almacenar en caché las consultas que utilizan rangos de:

```text
precio
peso
```

Esto reduce especialmente la generación de combinaciones prácticamente infinitas.

---

## 🔢 Límite de filtros

También es posible establecer un número máximo de valores de filtro que pueden utilizar el caché.

Por defecto:

```text
Máximo de filtros: 3
```

Una consulta con demasiados filtros puede ser excluida del caché.

---

# 🛡️ Protección cuando la tabla está llena

Si la tabla supera el tamaño máximo configurado, el módulo puede impedir temporalmente que nuevas consultas sigan alimentando el caché.

Esto evita el escenario:

```text
tabla llena
   ↓
entra nueva búsqueda
   ↓
se genera más caché
   ↓
tabla todavía más grande
   ↓
más consumo de disco
```

En su lugar:

```text
tabla llena
   ↓
se desactiva el caché para esa petición
   ↓
se ejecuta limpieza
   ↓
la tabla vuelve a estar bajo control
```

La desactivación se realiza en memoria para la petición actual, sin modificar permanentemente la configuración global de PrestaShop.

---

# ⚡ Limpieza inteligente

El limpiador utiliza diferentes estrategias dependiendo de la situación.

Entre ellas:

### Límite de tamaño

Elimina entradas hasta alcanzar el tamaño objetivo.

### Límite de filas

Permite establecer un número máximo de entradas.

### Entradas demasiado grandes

Las entradas que superen el tamaño máximo configurado pueden eliminarse.

### Entradas truncadas

Si el campo `data` utiliza `TEXT`, se detectan entradas que alcanzan el límite de almacenamiento de `TEXT`.

Estas entradas pueden provocar que PrestaShop tenga que regenerar continuamente el caché.

### TTL

Elimina entradas antiguas.

### Inactividad

Elimina entradas que no se han utilizado durante el período configurado.

### Optimización

Cuando existe suficiente espacio libre interno en InnoDB, el módulo puede ejecutar:

```sql
OPTIMIZE TABLE
```

para intentar recuperar espacio físico.

---

# 🚨 Truncate inteligente

Cuando es necesario eliminar una parte muy grande de la tabla, borrar las filas individualmente puede ser mucho más lento que vaciarla.

Por este motivo, el módulo puede utilizar:

```sql
TRUNCATE TABLE
```

cuando la cantidad de datos que debe eliminarse supera el porcentaje configurado.

El caché de `ps_facetedsearch` se regenera automáticamente conforme vuelve a ser necesario.

---

# ⏱️ Presupuesto de tiempo

Las tareas de limpieza funcionan con un límite de tiempo.

Valores predeterminados:

| Contexto     |       Tiempo |
| ------------ | -----------: |
| Front Office |   3 segundos |
| Back Office  |  20 segundos |
| Cron         | 120 segundos |

Esto permite que el Front Office realice tareas ligeras sin convertir una petición de usuario en una operación de mantenimiento de larga duración.

Las operaciones pesadas se reservan para Back Office y Cron.

---

# 🔒 Bloqueo de ejecuciones simultáneas

El limpiador utiliza un sistema de bloqueo para evitar que varias ejecuciones de limpieza trabajen simultáneamente sobre la misma tabla.

Si ya existe una limpieza ejecutándose, otra ejecución se cancela de forma segura.

---

# 📅 Columna `date_add`

El módulo puede añadir una columna:

```sql
date_add DATETIME
```

a:

```text
ps_layered_filter_block
```

Esto permite conocer la fecha real de creación de las entradas del caché y realizar una limpieza basada en antigüedad.

El módulo intenta utilizar:

```sql
ALGORITHM=INSTANT
```

cuando el servidor de base de datos lo permite.

Si no es posible, controla el tamaño de la tabla antes de realizar un `ALTER TABLE` potencialmente costoso.

---

# 📈 Estadísticas

El módulo obtiene información de:

```text
information_schema.TABLES
```

para controlar:

* número estimado de filas;
* tamaño de datos;
* tamaño de índices;
* espacio libre;
* tamaño físico;
* tamaño medio de fila.

Esto permite tomar decisiones sin tener que leer el campo `data` de cada entrada.

### Importante

El limpiador evita utilizar:

```sql
LENGTH(data)
```

sobre todas las filas para calcular el tamaño.

Esto es especialmente importante cuando la tabla contiene cientos de miles o millones de entradas.

---

# 🗄️ Tablas creadas por el módulo

El módulo crea dos tablas auxiliares.

## `ps_ecom_layeredtable_meta`

Guarda información de uso de las entradas del caché:

```text
hash
date_add
last_used
hits
size
```

## `ps_ecom_layeredtable_log`

Guarda el historial de las operaciones de mantenimiento:

```text
date_add
trigger_type
action
rows_deleted
mb_before
mb_after
rows_after
seconds
details
```

Estas tablas permiten conocer qué operaciones ha realizado el módulo y cuánto espacio ha recuperado.

---

# 🧩 Integración con ps_facetedsearch

El módulo utiliza hooks de PrestaShop y de `ps_facetedsearch`.

Hooks utilizados:

```text
actionProductSearchProviderRunQueryBefore
actionFacetedSearchCacheKeyGeneration
actionAdminControllerSetMedia
```

El hook:

```text
actionFacetedSearchCacheKeyGeneration
```

permite conocer con precisión los filtros seleccionados y realizar seguimiento del uso de las entradas de caché.

Para versiones antiguas de `ps_facetedsearch`, el módulo también dispone de un mecanismo alternativo basado en los filtros codificados de la URL.

---

# 🔄 Compatibilidad

## PrestaShop

Requiere:

```text
PrestaShop 8.0+
```

El módulo declara explícitamente una compatibilidad mínima con PrestaShop `8.0.0`.

## Base de datos

Está diseñado para trabajar con MySQL/MariaDB compatibles con las operaciones utilizadas por PrestaShop.

Para modificaciones de tablas grandes se tienen en cuenta las capacidades de:

```text
MariaDB 10.3+
MySQL 8.0+
```

especialmente para operaciones `ALTER TABLE ... ALGORITHM=INSTANT`.

---

# 📦 Instalación

## 1. Descargar

Clona el repositorio:

```bash
git clone https://github.com/ecomyseo/ecom_layeredtable.git
```

o descarga el ZIP desde GitHub.

## 2. Copiar el módulo

Copia la carpeta:

```text
ecom_layeredtable
```

en:

```text
/modules/
```

El resultado debe ser:

```text
/modules/ecom_layeredtable/
```

y dentro:

```text
ecom_layeredtable.php
```

## 3. Instalar desde PrestaShop

Ve a:

```text
Módulos
→ Gestor de módulos
```

Busca:

```text
Ecom Layered Table
```

e instala el módulo.

Durante la instalación se registran los hooks, se crean las tablas auxiliares y se inicializa la configuración.

---

# ⚙️ Configuración

Los principales parámetros disponibles son:

| Parámetro                | Valor por defecto | Descripción                          |
| ------------------------ | ----------------: | ------------------------------------ |
| `ELT_ENABLED`            |               `1` | Activa el módulo                     |
| `ELT_MAX_MB`             |             `950` | Tamaño máximo del caché              |
| `ELT_TARGET_PCT`         |              `70` | Porcentaje objetivo                  |
| `ELT_MAX_ROWS`           |               `0` | Máximo de filas, `0` = sin límite    |
| `ELT_MAX_ROW_KB`         |            `1024` | Tamaño máximo por entrada            |
| `ELT_STRATEGY`           |             `lru` | Estrategia de limpieza               |
| `ELT_BULK_TRUNCATE_PCT`  |              `60` | Umbral para limpieza masiva          |
| `ELT_EMERGENCY_TRUNCATE` |               `1` | Permitir limpieza de emergencia      |
| `ELT_ALTER_MAX_MB`       |             `500` | Tamaño máximo para ciertos ALTER     |
| `ELT_TTL_DAYS`           |               `7` | Antigüedad máxima                    |
| `ELT_UNUSED_DAYS`        |               `3` | Días sin utilización                 |
| `ELT_TRACK_USAGE`        |               `1` | Registrar utilización                |
| `ELT_OPTIMIZE`           |               `1` | Permitir OPTIMIZE                    |
| `ELT_OPTIMIZE_FREE_MB`   |             `200` | Espacio libre para lanzar OPTIMIZE   |
| `ELT_SKIP_BOTS`          |               `1` | No cachear bots                      |
| `ELT_SKIP_RANGES`        |               `1` | No cachear rangos                    |
| `ELT_MAX_FILTERS`        |               `3` | Máximo de filtros                    |
| `ELT_SKIP_WHEN_FULL`     |               `1` | Evitar caché cuando está lleno       |
| `ELT_AUTO_MINUTES`       |              `30` | Intervalo entre limpiezas            |
| `ELT_AUTO_FRONT`         |               `1` | Permitir limpieza desde Front Office |
| `ELT_FRONT_BUDGET_SEC`   |               `3` | Presupuesto Front Office             |
| `ELT_ADMIN_BUDGET_SEC`   |              `20` | Presupuesto Back Office              |
| `ELT_CRON_BUDGET_SEC`    |             `120` | Presupuesto Cron                     |
| `ELT_BATCH_ROWS`         |            `1000` | Filas por lote                       |
| `ELT_DEBUG_LOG`          |               `0` | Activar log de depuración            |
| `ELT_LOG_KEEP`           |             `500` | Número de logs conservados           |

---

# 🕐 Cron

El módulo incluye un controlador específico para ejecutar la limpieza mediante Cron.

La URL puede obtenerse desde el propio módulo y contiene un token de seguridad generado durante la instalación.

Ejemplo conceptual:

```bash
*/30 * * * * curl -fsS "https://example.com/module/ecom_layeredtable/cron?token=TOKEN"
```

Se recomienda utilizar Cron en tiendas con catálogos grandes para descargar del Front Office las tareas de mantenimiento más pesadas.

---

# 🔧 Funcionamiento recomendado

Para una tienda grande, una configuración inicial razonable puede ser:

```text
Tamaño máximo:          950 MB
Objetivo:               70 %
TTL:                    7 días
Sin uso:                3 días
Máximo filtros:         3
Excluir bots:           Sí
Excluir rangos:         Sí
Optimización:           Sí
Limpieza automática:    Sí
```

A partir de ahí se recomienda observar las estadísticas y ajustar los valores según el tráfico y tamaño del catálogo.

---

# 🧠 ¿Qué NO hace el módulo?

Este módulo **no sustituye a `ps_facetedsearch`**.

No genera los filtros.

No reemplaza el sistema de navegación por capas.

No modifica el catálogo.

No modifica los precios.

No modifica el stock.

Su objetivo es controlar el **caché generado por la navegación por capas**.

---

# ⚠️ Consideraciones importantes

### `TRUNCATE`

El caché puede vaciarse completamente cuando se supera el umbral configurado.

Esto no elimina productos ni configuraciones de filtros.

Simplemente obliga a `ps_facetedsearch` a regenerar las entradas que vuelvan a necesitarse.

### `OPTIMIZE TABLE`

En tablas grandes puede ser una operación costosa.

Por este motivo las operaciones pesadas están limitadas a Back Office/Cron y sujetas al presupuesto de ejecución.

### Front Office

La limpieza automática desde Front Office utiliza un presupuesto pequeño para minimizar el impacto sobre las peticiones de los clientes.

---

# 📂 Estructura

```text
ecom_layeredtable/
│
├── classes/
│   ├── EcomLayeredtableCleaner.php
│   └── EcomLayeredtableLogger.php
│
├── controllers/
│   ├── admin/
│   │   └── AdminEcomLayeredtableController.php
│   └── front/
│       └── cron.php
│
├── logs/
│
├── sql/
│   └── install.sql
│
├── translations/
│
├── views/
│   ├── css/
│   ├── js/
│   └── templates/
│
├── ecom_layeredtable.php
├── index.php
└── info.md
```

---

# 📝 Logs

El módulo dispone de un sistema de registro para las operaciones de mantenimiento.

Puede registrar:

* inicio de limpieza;
* filas eliminadas;
* MB liberados;
* acción realizada;
* duración;
* errores;
* motivo por el que se evita utilizar el caché;
* operaciones de optimización;
* operaciones `TRUNCATE`;
* problemas durante las modificaciones de la tabla.

El nivel de depuración puede activarse desde la configuración.

---

# 🔐 Seguridad

El controlador Cron utiliza un token generado automáticamente durante la instalación.

El módulo también evita actuar sobre la tabla si la medición de `information_schema` falla, evitando interpretar erróneamente una medición de `0 MB` como si la tabla estuviera vacía.

Además, las operaciones de limpieza utilizan un mecanismo de bloqueo para impedir ejecuciones simultáneas.

---

# 🛠️ Desarrollo

Repositorio:

https://github.com/ecomyseo/ecom_layeredtable

Para contribuir:

1. Haz un fork.
2. Crea una rama para tu modificación.
3. Realiza los cambios.
4. Comprueba la compatibilidad con PrestaShop 8+.
5. Comprueba las operaciones sobre tablas grandes.
6. Envía un Pull Request.

---

# 📄 Licencia

Este proyecto está publicado bajo:

**Academic Free License 3.0 (AFL-3.0)**

Copyright © 2026 Ecom Experts.

---

# 👨‍💻 Autor

**Ecom Experts**

Email:

```text
ecomyseo@gmail.com
```

Repositorio:

https://github.com/ecomyseo/ecom_layeredtable

---

## ⭐ Objetivo del proyecto

Mantener bajo control el caché de navegación por capas de PrestaShop sin tener que realizar continuamente limpiezas manuales de la base de datos.

Especialmente útil para:

* grandes catálogos;
* tiendas con muchos filtros;
* tiendas con mucho tráfico;
* tiendas atacadas por crawlers;
* tiendas donde `ps_layered_filter_block` alcanza cientos de MB o varios GB.

**Menos caché inútil. Menos crecimiento de la base de datos. Más control sobre `ps_facetedsearch`.**
