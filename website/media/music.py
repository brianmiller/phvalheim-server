"""Original 32-second instrumental. No samples or third-party music. Requires numpy."""
import sys,wave
import numpy as np
sr=44100;duration=32;mix=np.zeros(sr*duration);rng=np.random.default_rng(259)
def add(start,signal):
    a=int(start*sr);n=min(len(signal),len(mix)-a)
    if n>0:mix[a:a+n]+=signal[:n]
def tone(midi,length,volume=0.1):
    t=np.arange(int(sr*length))/sr;f=440*2**((midi-69)/12)
    envelope=np.minimum(t/.012,1)*np.exp(-t*3.8)
    return volume*(np.sin(2*np.pi*f*t)+.25*np.sin(4*np.pi*f*t))*envelope
chords=[[52,55,59],[48,52,55],[43,47,50],[50,54,57]]
for beat in range(64):
    start=beat*.5
    t=np.arange(int(sr*.27))/sr
    kick=.27*np.sin(2*np.pi*(43*t+58*.025*(1-np.exp(-t/.025))))*np.exp(-t*20)
    if beat%4 in (0,2):add(start,kick)
    if beat%4 in (1,3):
        t=np.arange(int(sr*.13))/sr
        noise=rng.normal(0,1,len(t));noise=np.concatenate(([0],np.diff(noise)))
        add(start,.035*noise*np.exp(-t*32))
    for off in (0,.25):
        t=np.arange(int(sr*.055))/sr;n=rng.normal(0,1,len(t));n=np.concatenate(([0],np.diff(n)))
        add(start+off,.012*n*np.exp(-t*85))
    chord=chords[(beat//8)%4]
    if beat%2==0:add(start,tone(chord[0]-12,.7,.13))
    add(start,tone(chord[(beat%8)%3]+12,.45,.065))
    if beat%2==1:add(start+.25,tone(chord[(beat+1)%3]+24,.2,.025))
    if beat%8==0:
        for note in chord:add(start,tone(note+12,2,.045))
fade=np.minimum(np.arange(len(mix))/sr/.3,1)*np.minimum((len(mix)-np.arange(len(mix)))/sr/1.2,1)
mix=np.tanh(mix*1.3)*fade
with wave.open(sys.argv[1],'w') as w:
    w.setnchannels(1);w.setsampwidth(2);w.setframerate(sr);w.writeframes((mix*26000).astype('<i2').tobytes())
