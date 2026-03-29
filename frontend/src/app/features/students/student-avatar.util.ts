import { Student } from '../../models/student';

export function resolveStudentAvatarCandidates(student: Student, apiBaseUrl: string): string[] {
  const avatar = student.profile?.avatar_url || student.avatar_url || student.user?.avatar;
  if (!avatar) {
    return [];
  }

  const apiOrigin = new URL(apiBaseUrl).origin;
  const candidates = new Set<string>();
  const normalized = avatar.trim();

  if (!normalized) {
    return [];
  }

  candidates.add(normalized);

  if (normalized.startsWith('data:')) {
    return Array.from(candidates);
  }

  if (normalized.startsWith('http://') || normalized.startsWith('https://')) {
    addStorageVariants(candidates, normalized);
    return Array.from(candidates);
  }

  const relativePath = normalized.replace(/^\/+/, '');

  if (relativePath.startsWith('public/storage/')) {
    const storagePath = relativePath.replace(/^public\/storage\//, '');
    candidates.add(`${apiOrigin}/public/storage/${storagePath}`);
    candidates.add(`${apiOrigin}/storage/${storagePath}`);
  } else if (relativePath.startsWith('storage/')) {
    const storagePath = relativePath.replace(/^storage\//, '');
    candidates.add(`${apiOrigin}/storage/${storagePath}`);
    candidates.add(`${apiOrigin}/public/storage/${storagePath}`);
  } else {
    candidates.add(`${apiOrigin}/storage/${relativePath}`);
    candidates.add(`${apiOrigin}/public/storage/${relativePath}`);
  }

  return Array.from(candidates);
}

export function resolveStudentAvatarUrl(student: Student, apiBaseUrl: string): string | null {
  return resolveStudentAvatarCandidates(student, apiBaseUrl)[0] ?? null;
}

function addStorageVariants(candidates: Set<string>, url: string): void {
  if (url.includes('/public/storage/')) {
    candidates.add(url.replace('/public/storage/', '/storage/'));
  }

  if (url.includes('/storage/')) {
    candidates.add(url.replace('/storage/', '/public/storage/'));
  }
}
