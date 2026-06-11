import { onBeforeUnmount, ref, type Ref } from 'vue';

export function useAudioLevelMeter(activeStream: Ref<MediaStream | null>) {
    const level = ref(0);

    let audioContext: AudioContext | null = null;
    let analyser: AnalyserNode | null = null;
    let source: MediaStreamAudioSourceNode | null = null;
    let rafId: number | null = null;
    let dataArray: Uint8Array<ArrayBuffer> | null = null;

    function stop(): void {
        if (rafId !== null) {
            cancelAnimationFrame(rafId);
            rafId = null;
        }

        source?.disconnect();
        source = null;
        analyser = null;

        if (audioContext) {
            void audioContext.close();
            audioContext = null;
        }

        level.value = 0;
        dataArray = null;
    }

    function tick(): void {
        if (!analyser || !dataArray) {
            return;
        }

        analyser.getByteFrequencyData(dataArray);
        let sum = 0;
        for (let i = 0; i < dataArray.length; i += 1) {
            sum += dataArray[i];
        }
        level.value = Math.min(1, (sum / dataArray.length) / 128);
        rafId = requestAnimationFrame(tick);
    }

    function attach(stream: MediaStream): void {
        stop();

        try {
            audioContext = new AudioContext();
            analyser = audioContext.createAnalyser();
            analyser.fftSize = 256;
            source = audioContext.createMediaStreamSource(stream);
            source.connect(analyser);
            dataArray = new Uint8Array(analyser.frequencyBinCount);
            rafId = requestAnimationFrame(tick);
        } catch {
            stop();
        }
    }

    function watchStream(streamRef: Ref<MediaStream | null>): void {
        const stopWatch = (): void => {
            if (streamRef.value) {
                attach(streamRef.value);
            } else {
                stop();
            }
        };

        stopWatch();

        const interval = setInterval(stopWatch, 100);

        onBeforeUnmount(() => {
            clearInterval(interval);
            stop();
        });
    }

    return {
        level,
        attach,
        stop,
        watchStream,
    };
}
