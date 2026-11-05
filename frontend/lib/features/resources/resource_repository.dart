import '../../core/app_config.dart';
import '../../core/cache/app_cache.dart';
import '../../core/network/api_client.dart';

class ResourceResult {
  const ResourceResult({
    required this.items,
    required this.meta,
    this.cachedAt,
  });
  final List<Map<String, dynamic>> items;
  final Map<String, dynamic> meta;
  final DateTime? cachedAt;
}

class ResourceRepository {
  ResourceRepository(this.api, this.cache, this.userId);
  final ApiClient api;
  final AppCache cache;
  final int userId;

  ResourceResult? cached(String endpoint, Map<String, dynamic> query) {
    final value = cache.read(userId, endpoint, query.toString());
    if (value == null) return null;
    return _parse(value.value, cachedAt: value.savedAt);
  }

  Future<ResourceResult> list(
    String endpoint,
    Map<String, dynamic> query,
  ) async {
    final json = await api.get(
      '${AppConfig.apiPrefix}/$endpoint',
      query: query,
    );
    await cache.write(userId, endpoint, query.toString(), json);
    return _parse(json);
  }

  ResourceResult _parse(dynamic json, {DateTime? cachedAt}) {
    final map = json is Map<String, dynamic> ? json : <String, dynamic>{};
    final raw = map['data'] is List ? map['data'] as List : const [];
    return ResourceResult(
      items: raw
          .whereType<Map>()
          .map((item) => Map<String, dynamic>.from(item))
          .toList(),
      meta: map['meta'] is Map
          ? Map<String, dynamic>.from(map['meta'] as Map)
          : const {},
      cachedAt: cachedAt,
    );
  }

  Future<Map<String, dynamic>> save(
    String endpoint,
    Map<String, dynamic> data, {
    int? id,
  }) async {
    final json = id == null
        ? await api.post('${AppConfig.apiPrefix}/$endpoint', body: data)
        : await api.put('${AppConfig.apiPrefix}/$endpoint/$id', body: data);
    return Map<String, dynamic>.from((json as Map)['data'] as Map? ?? json);
  }

  Future<void> remove(String endpoint, int id) =>
      api.delete('${AppConfig.apiPrefix}/$endpoint/$id');
}
