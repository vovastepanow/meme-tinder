// video-controls.js — свои кнопки плеера в просмотрщике вместо стандартных: воспроизведение, радужная полоса перемотки,
// время, звук с громкостью и полный экран. Клавиши (пока открыто видео): пробел — пауза, ←/→ — 5 секунд назад/вперёд,
// M — звук, F — полный экран.
import { $ } from './core.js';

const SEEK_STEP = 5;      // секунд на стрелку
const VOLUME_STEP = 0.1;  // громкость на стрелку вверх/вниз

function formatTime(seconds) {
    if (!Number.isFinite(seconds) || seconds < 0) return '0:00';
    const whole = Math.floor(seconds);
    const hours = Math.floor(whole / 3600);
    const minutes = Math.floor(whole / 60) % 60;
    const secs = String(whole % 60).padStart(2, '0');
    return hours ? `${hours}:${String(minutes).padStart(2, '0')}:${secs}` : `${minutes}:${secs}`;
}

/**
 * Ползунок из div: значение 0..1 по клику и перетаскиванию (с захватом указателя), стрелками с клавиатуры.
 * onInput(ratio) вызывается при каждом изменении пользователем.
 */
function bindSlider(slider, onInput, keyStep) {
    const ratioAt = event => {
        const rect = slider.getBoundingClientRect();
        return Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));
    };
    slider.addEventListener('pointerdown', event => {
        if (event.button !== 0) return;
        slider.setPointerCapture(event.pointerId);
        slider.classList.add('is-dragging');
        onInput(ratioAt(event));
    });
    slider.addEventListener('pointermove', event => {
        if (slider.hasPointerCapture(event.pointerId)) onInput(ratioAt(event));
    });
    const release = () => slider.classList.remove('is-dragging');
    slider.addEventListener('pointerup', release);
    slider.addEventListener('pointercancel', release);
    slider.addEventListener('keydown', event => {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
        event.preventDefault();
        event.stopPropagation(); // иначе стрелку обработает и общий обработчик клавиш плеера
        keyStep(event.key === 'ArrowRight' ? 1 : -1);
    });
}

/** Заливка всегда во всю дорожку и обрезается справа: радуга привязана к дорожке, а не растягивается вместе с заливкой. */
function setSlider(fill, slider, ratio) {
    const percent = Math.min(1, Math.max(0, ratio)) * 100;
    fill.style.clipPath = `inset(0 ${(100 - percent).toFixed(2)}% 0 0 round 999px)`;
    slider.setAttribute('aria-valuenow', String(Math.round(percent)));
}

export function initializeVideoControls() {
    const video = $('image-viewer-video');
    const controls = $('video-controls');
    const stage = $('image-viewer-stage');
    const progress = $('vc-progress');
    const volume = $('vc-volume');

    const renderPlay = () => {
        controls.classList.toggle('is-paused', video.paused);
        $('vc-play').setAttribute('aria-label', video.paused ? 'Смотреть' : 'Пауза');
    };
    const renderTime = () => {
        const duration = video.duration;
        const ratio = Number.isFinite(duration) && duration > 0 ? video.currentTime / duration : 0;
        setSlider($('vc-progress-fill'), progress, ratio);
        $('vc-time').textContent = `${formatTime(video.currentTime)} / ${formatTime(duration)}`;
    };
    const renderBuffered = () => {
        const duration = video.duration;
        const end = video.buffered.length ? video.buffered.end(video.buffered.length - 1) : 0;
        $('vc-buffered').style.width = Number.isFinite(duration) && duration > 0 ? `${Math.min(100, end / duration * 100)}%` : '0%';
    };
    const renderVolume = () => {
        const level = video.muted ? 0 : video.volume;
        setSlider($('vc-volume-fill'), volume, level);
        controls.classList.toggle('is-muted', level === 0);
        $('vc-mute').setAttribute('aria-label', level === 0 ? 'Включить звук' : 'Выключить звук');
    };

    const togglePlay = () => {
        if (video.paused) video.play().catch(() => {}); else video.pause();
    };
    const seekBy = seconds => {
        if (Number.isFinite(video.duration)) video.currentTime = Math.min(video.duration, Math.max(0, video.currentTime + seconds));
    };
    const setVolume = level => {
        video.volume = Math.min(1, Math.max(0, level));
        video.muted = video.volume === 0;
    };
    const toggleMute = () => {
        if (video.muted || video.volume === 0) {
            video.muted = false;
            if (video.volume === 0) video.volume = 0.33;
        } else {
            video.muted = true;
        }
    };
    // Во весь экран уходит вся сцена, а не только <video>: так наши кнопки остаются на экране
    const toggleFullscreen = () => {
        if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
        else stage.requestFullscreen?.().catch(() => {});
    };

    $('vc-play').addEventListener('click', togglePlay);
    $('vc-mute').addEventListener('click', toggleMute);
    $('vc-fullscreen').addEventListener('click', toggleFullscreen);
    bindSlider(progress, ratio => {
        if (Number.isFinite(video.duration)) video.currentTime = ratio * video.duration;
    }, direction => seekBy(direction * SEEK_STEP));
    bindSlider(volume, setVolume, direction => setVolume((video.muted ? 0 : video.volume) + direction * VOLUME_STEP));

    video.addEventListener('play', renderPlay);
    video.addEventListener('pause', renderPlay);
    video.addEventListener('timeupdate', renderTime);
    video.addEventListener('durationchange', renderTime);
    video.addEventListener('loadedmetadata', () => { renderTime(); renderBuffered(); });
    video.addEventListener('progress', renderBuffered);
    video.addEventListener('volumechange', renderVolume);
    video.addEventListener('emptied', () => { renderTime(); renderBuffered(); renderPlay(); });
    document.addEventListener('fullscreenchange', () => stage.classList.toggle('is-fullscreen', document.fullscreenElement === stage));

    document.addEventListener('keydown', event => {
        if ($('image-viewer').hidden || !$('image-viewer').classList.contains('is-video')) return;
        if (event.target.closest?.('input, textarea, select')) return;
        const key = event.key.toLowerCase();
        if (key === ' ' && event.target.closest?.('button')) return; // пробел на кнопке и так нажмёт её
        if (key === ' ' || key === 'k') togglePlay();
        else if (event.key === 'ArrowLeft') seekBy(-SEEK_STEP);
        else if (event.key === 'ArrowRight') seekBy(SEEK_STEP);
        else if (key === 'm' || key === 'ь') toggleMute();
        else if (key === 'f' || key === 'а') toggleFullscreen();
        else return;
        event.preventDefault();
    });

    renderPlay();
    renderTime();
    renderVolume();
}
