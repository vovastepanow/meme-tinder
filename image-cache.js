/**
 * Image Cache System for Postmaker
 * Cookie-based image caching and thumbnail generation
 */

export interface CachedImage {
    id: string;
    url: string;
    timestamp: number;
    cacheExpiry: number;
    thumbnail?: string;
    size?: { width: number; height: number };
}

export class ImageCache {
    static readonly CACHE_NAME = 'postmaker_images';
    static readonly MAX_CACHE_SIZE = 50;
    static readonly DEFAULT_EXPIRY = 24 * 60 * 60 * 1000; // 24 hours

    static getCachedImages(): CachedImage[] {
        const cookies = document.cookie.split(';');
        const cacheCookie = cookies.find(c => c.trim().startsWith(`${this.CACHE_NAME}=`));
        if (!cacheCookie) return [];

        try {
            const base64Data = cacheCookie.split('=')[1];
            const data = JSON.parse(atob(base64Data)) as CachedImage[];
            const now = Date.now();
            return data.filter(img => img.cacheExpiry > now);
        } catch (e) {
            console.error('Failed to parse image cache:', e);
            return [];
        }
    }

    static setCachedImages(images: CachedImage[]): void {
        const now = Date.now();
        const validImages = images.filter(img => img.cacheExpiry > now);
        validImages.sort((a, b) => b.timestamp - a.timestamp);
        const limitedImages = validImages.slice(0, this.MAX_CACHE_SIZE);

        try {
            const base64Data = btoa(JSON.stringify(limitedImages));
            const expires = new Date(now + this.DEFAULT_EXPIRY);
            document.cookie = `${this.CACHE_NAME}=${base64Data}; expires=${expires.toUTCString()}; path=/; SameSite=Strict`;
        } catch (e) {
            console.error('Failed to save image cache:', e);
        }
    }

    static updateImage(image: CachedImage): void {
        const cached = this.getCachedImages();
        const existingIndex = cached.findIndex(img => img.id === image.id);

        if (existingIndex >= 0) {
            cached[existingIndex] = { ...image, timestamp: Date.now() };
        } else {
            cached.push(image);
        }

        this.setCachedImages(cached);
    }

    static getImage(id: string): CachedImage | null {
        const cached = this.getCachedImages();
        return cached.find(img => img.id === id) || null;
    }

    static cleanupExpired(): void {
        const cached = this.getCachedImages();
        const validImages = cached.filter(img => img.cacheExpiry > Date.now());
        if (validImages.length !== cached.length) {
            this.setCachedImages(validImages);
        }
    }

    static generateImageId(url: string): string {
        return btoa(url).replace(/[^a-zA-Z0-9]/g, '').substring(0, 16);
    }

    static async generateThumbnail(url: string, maxWidth = 150, maxHeight = 150): Promise<string | null> {
        return new Promise((resolve) => {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => {
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                if (!ctx) {
                    resolve(null);
                    return;
                }

                let { width, height } = img;
                if (width > height) {
                    if (width > maxWidth) {
                        height = Math.round(height * (maxWidth / width));
                        width = maxWidth;
                    }
                } else {
                    if (height > maxHeight) {
                        width = Math.round(width * (maxHeight / height));
                        height = maxHeight;
                    }
                }

                canvas.width = width;
                canvas.height = height;
                ctx.drawImage(img, 0, 0, width, height);
                resolve(canvas.toDataURL('image/jpeg', 0.7));
            };
            img.onerror = () => resolve(null);
            img.src = url;
        });
    }

    static async cacheImage(url: string): Promise<CachedImage | null> {
        const id = this.generateImageId(url);
        const existing = this.getImage(id);

        if (existing && existing.cacheExpiry > Date.now()) {
            return existing;
        }

        try {
            const img = await new Promise<HTMLImageElement>((resolve, reject) => {
                const image = new Image();
                image.crossOrigin = 'anonymous';
                image.onload = () => resolve(image);
                image.onerror = reject;
                image.src = url;
            });

            const thumbnail = await this.generateThumbnail(url);

            const cachedImage: CachedImage = {
                id,
                url,
                timestamp: Date.now(),
                cacheExpiry: Date.now() + this.DEFAULT_EXPIRY,
                thumbnail,
                size: { width: img.naturalWidth, height: img.naturalHeight }
            };

            this.updateImage(cachedImage);
            return cachedImage;
        } catch (e) {
            console.error('Failed to cache image:', e);
            return null;
        }
    }

    static async getImageWithCache(url: string, forceRefresh = false): Promise<CachedImage | null> {
        const id = this.generateImageId(url);
        const cached = this.getImage(id);

        if (cached && !forceRefresh && cached.cacheExpiry > Date.now()) {
            return cached;
        }

        return this.cacheImage(url);
    }
}

export const imageCacheUtils = {
    cleanup: () => ImageCache.cleanupExpired(),
    refreshImage: (url: string) => ImageCache.getImageWithCache(url, true),
    getCached: (url: string) => ImageCache.getImage(ImageCache.generateImageId(url)),
    cacheImage: (url: string) => ImageCache.cacheImage(url),
    getImageWithCache: (url: string, forceRefresh = false) => ImageCache.getImageWithCache(url, forceRefresh)
};
