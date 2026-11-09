# Documentación API Legacy v1 - Sistema Notarial

## Resumen

Esta documentación describe todos los endpoints de la API v1 del sistema notarial legacy ubicado en `../notarial/api/v1/`.

**Base URL:** `/api/v1/`

---

## Seguridad

### Autenticación JWT (RS256)

El sistema utiliza **JWT (JSON Web Tokens)** con algoritmo **RS256** (RSA Signature with SHA-256) para la autenticación.

#### Características:
- **Algoritmo:** RS256 (clave privada PEM)
- **Access Token:** Válido por 1 hora (3600 segundos)
- **Refresh Token:** Válido por 24 horas (86400 segundos)
- **Issuer:** `security@notarial`
- **Audience:** `apps@notarial`

#### Payload del Token:
```json
{
  "iss": "security@notarial",
  "aud": "apps@notarial",
  "userId": 1,
  "notaryId": 1,
  "features": ["ESC", "PAG"],
  "isRefresh": false,
  "iat": 1699999999,
  "nbf": 1699999999,
  "exp": 1700003599
}
```

#### Header de Autorización:
```
Authorization: Bearer <access_token>
```

#### Multi-tenancy:
- Todas las operaciones filtran por `notaryId` extraído del token
- No se permite acceso cross-tenant

---

## Endpoints

### 1. Authentication (oauth.php)

#### POST /api/v1/oauth.php?type=login
**Descripción:** Autenticación de usuario

**Request:**
```json
{
  "user": "string",
  "password": "string"
}
```

**Response (200):**
```json
{
  "accessToken": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9...",
  "refreshToken": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9..."
}
```

**Response (401):** Credenciales inválidas (sin body)

---

#### POST /api/v1/oauth.php?type=refresh
**Descripción:** Renovar token de acceso

**Headers:**
```
Authorization: Bearer <refresh_token>
```

**Response (200):**
```json
{
  "accessToken": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9...",
  "refreshToken": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9..."
}
```

**Response (400):** Token no proporcionado
**Response (403):** Token inválido o expirado

---

### 2. Acts - Actos Jurídicos (act.php)

> **Requiere autenticación:** Sí

#### GET /api/v1/act.php
**Descripción:** Listar todos los actos de la notaría

**Response (200):**
```json
[
  {
    "actId": 1,
    "notaryId": 1,
    "actName": "Compraventa",
    "actDescription": "Acto de compraventa de inmuebles",
    "userId": 1,
    "modificationDate": "2024-01-15 10:30:00",
    "statusId": 1
  }
]
```

**Response (403):** Sin permiso para acceder

---

#### GET /api/v1/act.php?id={actId}
**Descripción:** Obtener un acto por ID

**Response (200):**
```json
{
  "actId": 1,
  "notaryId": 1,
  "actName": "Compraventa",
  "actDescription": "Acto de compraventa de inmuebles",
  "userId": 1,
  "modificationDate": "2024-01-15 10:30:00",
  "statusId": 1
}
```

**Response (404):** Acto no encontrado

---

#### POST /api/v1/act.php
**Descripción:** Crear un nuevo acto

**Request:**
```json
{
  "actName": "string (requerido)",
  "actDescription": "string (requerido)",
  "status": 1
}
```

**Response (201):** Creado exitosamente (sin body)
**Response (400):** Datos inválidos

---

#### PUT /api/v1/act.php?id={actId}
**Descripción:** Actualizar un acto existente

**Request:**
```json
{
  "actName": "string (requerido)",
  "actDescription": "string (requerido)",
  "status": 1
}
```

**Response (204):** Actualizado exitosamente (sin body)
**Response (400):** Datos inválidos

---

### 3. Deeds - Escrituras (deed.php)

> **Requiere autenticación:** Sí

#### GET /api/v1/deed.php
**Descripción:** Listar escrituras (paginado)

**Query Parameters:**
- `pg` (opcional): Número de página (default: 1)
- `filter` (opcional): Filtro de búsqueda

**Response (200):**
```json
{
  "data": [
    {
      "deedId": 1,
      "deedNum": "12345",
      "book": "LIBRO-2024",
      "deedDate": "2024-01-15",
      "actName": "Compraventa",
      "subdivisionName": "Fraccionamiento Centro",
      "property": "Lote 1, Manzana A",
      "isFinished": 0
    }
  ],
  "count": 150
}
```

**Nota:** Paginación de 100 registros por página

---

#### GET /api/v1/deed.php?id={deedId}
**Descripción:** Obtener detalle de una escritura

**Response (200):**
```json
{
  "deedId": 1,
  "notaryId": 1,
  "deedNum": "12345",
  "book": "LIBRO-2024",
  "deedDate": "2024-01-15",
  "actId": 1,
  "actName": "Compraventa",
  "typeDeedId": 1,
  "subdivisionId": 1,
  "subdivisionName": "Fraccionamiento Centro",
  "property": "Lote 1, Manzana A",
  "deliveryDate": "2024-02-01",
  "deliveryPerson": "Juan Pérez",
  "comments": "Notas adicionales",
  "isFinished": 0,
  "inscriptions": [
    {
      "inscriptionId": 1,
      "deedId": 1,
      "number": "001",
      "section": "A",
      "tome": "I",
      "date": "2024-01-20"
    }
  ],
  "participants": [
    {
      "participantId": 1,
      "deedId": 1,
      "name": "María García",
      "type": 1,
      "status": 1
    }
  ]
}
```

**Response (404):** Escritura no encontrada

---

#### POST /api/v1/deed.php
**Descripción:** Crear una nueva escritura

**Request:**
```json
{
  "deedNum": "string (requerido)",
  "book": "string (requerido)",
  "deedDate": "YYYY-MM-DD (requerido)",
  "actId": "int (requerido)",
  "typeDeedId": "int (requerido)",
  "subdivisionId": "int (opcional)",
  "property": "string (opcional)",
  "deliveryDate": "YYYY-MM-DD (opcional)",
  "deliveryPerson": "string (opcional)",
  "comments": "string (opcional)",
  "isFinished": "int (0|1, opcional, default: 0)",
  "participants": [
    {
      "name": "string",
      "type": "int (1=Comprador, 2=Vendedor, etc.)"
    }
  ],
  "inscriptions": [
    {
      "number": "string",
      "section": "string",
      "tome": "string",
      "date": "YYYY-MM-DD"
    }
  ]
}
```

**Response (201):**
```json
{
  "deedId": 123
}
```

**Response (400):**
```json
{
  "error": "Campos requeridos: deedNum, book, deedDate, actId, typeDeedId"
}
```

---

#### PUT /api/v1/deed.php?id={deedId}
**Descripción:** Actualizar una escritura

**Request:** (mismo formato que POST)

**Response (204):** Actualizado exitosamente (sin body)
**Response (400):** Datos inválidos
**Response (404):** Escritura no encontrada

---

### 4. Deed Types - Tipos de Escritura (deed_type.php)

> **Requiere autenticación:** Sí

#### GET /api/v1/deed_type.php
**Descripción:** Listar tipos de escritura

**Response (200):**
```json
[
  {
    "deedTypeId": 1,
    "notaryId": 1,
    "name": "Escritura Pública",
    "description": "Documento notarial formal",
    "maxInscription": 3,
    "userId": 1,
    "activeSubdivision": 1,
    "dateModification": "2024-01-15 10:30:00",
    "status": 1
  }
]
```

---

#### GET /api/v1/deed_type.php?id={deedTypeId}
**Descripción:** Obtener tipo de escritura por ID

**Response (200):**
```json
{
  "deedTypeId": 1,
  "notaryId": 1,
  "name": "Escritura Pública",
  "description": "Documento notarial formal",
  "maxInscription": 3,
  "userId": 1,
  "activeSubdivision": 1,
  "dateModification": "2024-01-15 10:30:00",
  "status": 1
}
```

**Response (404):** Tipo no encontrado

---

#### POST /api/v1/deed_type.php
**Descripción:** Crear tipo de escritura

**Request:**
```json
{
  "name": "string (requerido)",
  "description": "string (requerido)",
  "maxInscription": "int (requerido)",
  "enableSubdivision": "int (0|1, requerido)",
  "status": "int (requerido)"
}
```

**Response (201):** Creado exitosamente
**Response (400):** Datos inválidos

---

#### PUT /api/v1/deed_type.php?id={deedTypeId}
**Descripción:** Actualizar tipo de escritura

**Request:** (mismo formato que POST)

**Response (204):** Actualizado exitosamente
**Response (400):** Datos inválidos

---

### 5. Subdivisions - Fraccionamientos (subdivision.php)

> **Requiere autenticación:** Sí

#### GET /api/v1/subdivision.php
**Descripción:** Listar fraccionamientos

**Response (200):**
```json
[
  {
    "subdivisionId": 1,
    "notaryId": 1,
    "name": "Fraccionamiento Centro",
    "userId": 1,
    "modificationDate": "2024-01-15 10:30:00",
    "status": 1
  }
]
```

---

#### GET /api/v1/subdivision.php?id={subdivisionId}
**Descripción:** Obtener fraccionamiento por ID

**Response (200):**
```json
{
  "subdivisionId": 1,
  "notaryId": 1,
  "name": "Fraccionamiento Centro",
  "userId": 1,
  "modificationDate": "2024-01-15 10:30:00",
  "status": 1
}
```

**Response (404):** Fraccionamiento no encontrado

---

#### POST /api/v1/subdivision.php
**Descripción:** Crear fraccionamiento

**Request:**
```json
{
  "name": "string (requerido)",
  "status": "int (requerido)"
}
```

**Response (201):** Creado exitosamente
**Response (400):** Datos inválidos

---

#### PUT /api/v1/subdivision.php?id={subdivisionId}
**Descripción:** Actualizar fraccionamiento

**Request:** (mismo formato que POST)

**Response (204):** Actualizado exitosamente
**Response (400):** Datos inválidos

---

### 6. Notary - Notaría (notary.php)

> **Requiere autenticación:** Sí

#### GET /api/v1/notary.php
**Descripción:** Obtener información de la notaría del usuario

**Response (200):**
```json
{
  "notaryId": 1,
  "name": "Notaría 25",
  "mainNotary": "Lic. Roberto Hernández",
  "city": "Guadalajara",
  "address": "Av. Vallarta 1234",
  "phone": "33-1234-5678",
  "rfc": "NOT250101ABC",
  "pathLogo": "/assets/logos/notaria25.png"
}
```

**Response (404):** Notaría no encontrada

---

#### GET /api/v1/notary.php?type=user
**Descripción:** Obtener información del usuario actual

**Response (200):**
```json
{
  "userId": 1,
  "notaryId": 1,
  "name": "Juan",
  "lastName": "Pérez",
  "lastNameTwo": "García",
  "user": "jperez",
  "status": 1,
  "lastAccess": "2024-01-15 10:30:00",
  "userAccess": "ESC,PAG"
}
```

**Response (404):** Usuario no encontrado

---

#### PUT /api/v1/notary.php
**Descripción:** Actualizar información de la notaría

**Request:**
```json
{
  "name": "string (requerido)",
  "mainNotary": "string (requerido)",
  "city": "string (requerido)",
  "address": "string (requerido)",
  "phone": "string (requerido)",
  "rfc": "string (requerido)",
  "logoPath": "string (opcional)"
}
```

**Response (204):** Actualizado exitosamente
**Response (400):** Datos inválidos
**Response (404):** Notaría no encontrada

---

### 7. Payment Clients - Clientes de Pago (payments_client.php)

> **Requiere autenticación:** Sí

#### GET /api/v1/payments_client.php
**Descripción:** Listar clientes de pago

**Query Parameters:**
- `filter` (opcional): Filtro de búsqueda por nombre

**Response (200):**
```json
[
  {
    "clientId": 1,
    "notaryId": 1,
    "fullName": "María García López",
    "phone": "33-9876-5432",
    "email": "maria@email.com",
    "status": 1
  }
]
```

---

#### GET /api/v1/payments_client.php?id={clientId}
**Descripción:** Obtener cliente por ID

**Response (200):**
```json
{
  "clientId": 1,
  "notaryId": 1,
  "fullName": "María García López",
  "phone": "33-9876-5432",
  "email": "maria@email.com",
  "status": 1
}
```

**Response (404):** Cliente no encontrado

---

#### POST /api/v1/payments_client.php
**Descripción:** Crear cliente

**Request:**
```json
{
  "fullName": "string (requerido)",
  "phone": "string (requerido)",
  "email": "string (requerido)",
  "status": "int (requerido)"
}
```

**Response (201):** Creado exitosamente
**Response (400):** Datos inválidos

---

#### PUT /api/v1/payments_client.php?id={clientId}
**Descripción:** Actualizar cliente

**Request:** (mismo formato que POST)

**Response (204):** Actualizado exitosamente
**Response (400):** Datos inválidos

---

### 8. Payment Procedures - Procedimientos de Pago (payments_procedure.php)

> **Requiere autenticación:** Sí

#### GET /api/v1/payments_procedure.php
**Descripción:** Listar procedimientos de pago

**Response (200):**
```json
[
  {
    "procedureId": 1,
    "notaryId": 1,
    "name": "Trámite de Compraventa",
    "status": 1
  }
]
```

---

#### GET /api/v1/payments_procedure.php?id={procedureId}
**Descripción:** Obtener procedimiento por ID

**Response (200):**
```json
{
  "procedureId": 1,
  "notaryId": 1,
  "name": "Trámite de Compraventa",
  "status": 1
}
```

**Response (404):** Procedimiento no encontrado

---

#### POST /api/v1/payments_procedure.php
**Descripción:** Crear procedimiento

**Request:**
```json
{
  "procedureName": "string (requerido)",
  "status": "int (requerido)"
}
```

**Response (201):** Creado exitosamente
**Response (400):** Datos inválidos

---

#### PUT /api/v1/payments_procedure.php?id={procedureId}
**Descripción:** Actualizar procedimiento

**Request:** (mismo formato que POST)

**Response (204):** Actualizado exitosamente
**Response (400):** Datos inválidos

---

### 9. Payment Taxes - Impuestos/Conceptos (payments_tax.php)

> **Requiere autenticación:** Sí

#### GET /api/v1/payments_tax.php
**Descripción:** Listar impuestos/conceptos de pago

**Response (200):**
```json
[
  {
    "taxId": 1,
    "notaryId": 1,
    "taxName": "ISR",
    "status": 1
  }
]
```

---

#### GET /api/v1/payments_tax.php?id={taxId}
**Descripción:** Obtener impuesto por ID

**Response (200):**
```json
{
  "taxId": 1,
  "notaryId": 1,
  "taxName": "ISR",
  "status": 1
}
```

**Response (404):** Impuesto no encontrado

---

#### POST /api/v1/payments_tax.php
**Descripción:** Crear impuesto

**Request:**
```json
{
  "taxName": "string (requerido)",
  "status": "int (requerido)"
}
```

**Response (201):** Creado exitosamente
**Response (400):** Datos inválidos

---

#### PUT /api/v1/payments_tax.php?id={taxId}
**Descripción:** Actualizar impuesto

**Request:** (mismo formato que POST)

**Response (204):** Actualizado exitosamente
**Response (400):** Datos inválidos

---

## Códigos de Estado HTTP

| Código | Descripción |
|--------|-------------|
| 200 | Operación exitosa |
| 201 | Recurso creado |
| 204 | Actualización exitosa (sin contenido) |
| 400 | Solicitud inválida / Datos faltantes |
| 401 | No autenticado |
| 403 | Sin permisos / Token inválido |
| 404 | Recurso no encontrado |
| 405 | Método no permitido |

---

## Valores de Status

En todo el sistema se usa la siguiente convención para estados:

| Valor | Significado |
|-------|-------------|
| 1 | Activo |
| 2 | Inactivo |

---

## Tipos de Participante (Deed Participants)

| Valor | Significado |
|-------|-------------|
| 1 | Comprador |
| 2 | Vendedor |
| (otros) | Definidos por negocio |

---

## Features de Usuario (userAccess)

El campo `userAccess` en el token contiene los módulos a los que el usuario tiene acceso, separados por coma:

| Código | Módulo |
|--------|--------|
| ESC | Escrituras (Deeds) |
| PAG | Pagos (Payments) |

---

## Notas Importantes

1. **Timezone:** Todas las fechas se manejan en `America/Mexico_City`
2. **Formato de fechas:** `YYYY-MM-DD` para fechas simples, `YYYY-MM-DD HH:ii:ss` para timestamps
3. **Paginación de escrituras:** 100 registros por página
4. **DELETE:** La mayoría de los endpoints no implementan DELETE (soft delete vía status)
5. **Validación de notaría:** Todas las operaciones validan que el recurso pertenezca a la notaría del usuario autenticado

---

## Endpoints NO Implementados

Los siguientes métodos están definidos pero no implementados:
- `DELETE` en act.php
- `DELETE` en deed_type.php
- `DELETE` en subdivision.php
- `DELETE` en deed.php (retorna 405)
- `DELETE` en payments_client.php (retorna 405)
- `DELETE` en payments_procedure.php (retorna 405)
- `DELETE` en payments_tax.php (retorna 405)
- `POST` en notary.php (retorna 405)
- `DELETE` en notary.php (retorna 405)
