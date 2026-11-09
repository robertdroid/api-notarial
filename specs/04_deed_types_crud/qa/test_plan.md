# Plan de Pruebas: CRUD Tipos de Escritura

## Información General
- **Feature:** 04_deed_types_crud
- **Rama/PR:** feature/04-deed-types-crud / PR #4
- **QA:** Claude Code
- **Fecha:** 2026-11-09

## Alcance de Pruebas

### En Scope
- 4 endpoints CRUD para tipos de escritura
- Validaciones de campos (name, description, maxInscriptions, enableSubdivisions, status)
- Filtros de búsqueda (status, name)
- Multi-tenancy (aislamiento por notaría)
- Autenticación JWT
- Casos edge (trim, IDs inválidos, valores fuera de rango)

### Fuera de Scope
- Pruebas de carga/performance
- Pruebas de seguridad avanzadas (penetration testing)
- UI/Frontend (solo API)

## Tipos de Pruebas
- [x] Funcionales (API)
- [x] Validaciones de entrada
- [x] Multi-tenancy
- [x] Autenticación
- [x] Casos edge

## Ambiente de Pruebas
- PHP: 8.3 (Docker)
- MariaDB: 11.4 (Docker)
- API URL: http://localhost:8080
- Docker: Running

## Casos de Prueba

| # | Escenario | Prioridad |
|---|-----------|-----------|
| 1 | Listar todos los tipos | Alta |
| 2 | Filtrar por status | Alta |
| 3 | Filtrar por nombre (case-insensitive) | Alta |
| 4 | Combinar filtros | Media |
| 5 | Obtener tipo por ID | Alta |
| 6 | Obtener tipo inexistente | Alta |
| 7 | Acceso a tipo de otra notaría | Alta |
| 8 | Crear tipo exitosamente | Alta |
| 9 | Crear con nombre duplicado | Alta |
| 10 | Crear con datos inválidos | Alta |
| 11 | Actualizar tipo exitosamente | Alta |
| 12 | Desactivar tipo (soft delete) | Media |
| 13 | Actualizar tipo de otra notaría | Alta |
| 14 | Actualizar con nombre duplicado | Alta |
| 15 | Acceso sin token | Alta |
| 16 | Token inválido/expirado | Alta |
| 17 | maxInscriptions personalizado | Media |
| 18 | enableSubdivisions=true | Media |
| E1 | Nombre con espacios (trim) | Media |
| E2 | ID con formato inválido | Media |
| E3 | maxInscriptions = 0 | Media |
| E4 | maxInscriptions negativo | Media |
| E5 | Status inválido | Media |
